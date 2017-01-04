<?php
/**
 * Created by PhpStorm.
 * User: paul
 * Date: 03/01/17
 * Time: 16:31
 */

namespace Victoire\Bundle\BusinessEntityBundle\Command;


use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Knp\DoctrineBehaviors\Model\Translatable\Translatable;
use Victoire\Bundle\BusinessEntityBundle\Entity\BusinessEntity;
use Victoire\Bundle\CoreBundle\Annotations\BusinessProperty as BusinessPropertyAnnotation;
use Victoire\Bundle\BusinessEntityBundle\Entity\BusinessProperty;
use Victoire\Bundle\ORMBusinessEntityBundle\Entity\ORMBusinessEntity;

class BusinessEntityDBMigrationCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        parent::configure();

        $this
            ->setName('victoire:businessEntities:migrate')
            ->setDescription('migrate from annnotations to database BE management');
    }

    /**
     * Read declared business entities and BusinessEntityPatternPages to generate their urls.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ProgressHelper $progress */
        $progress = $this->getHelperSet()->get('progress');
        $progress->setProgressCharacter('V');
        $progress->setEmptyBarCharacter('-');

        $progress->start($output);
        foreach ($this->getContainer()->get('victoire_business_entity.annotation_driver')->getAllClassNames() as $k => $className) {
            $this->parse(new \ReflectionClass($className));
            $progress->advance($k);
        }
        $progress->finish();

    }


    /**
     * Parse the given Class to find some annotations related to BusinessEntities.
     */
    public function parse(\ReflectionClass $class)
    {
        $entityManager = $this->getContainer()->get('doctrine.orm.entity_manager');
        $classPath = dirname($class->getFileName());
        $inPaths = false;
        foreach ($this->getContainer()->getParameter('victoire_core.base_paths') as $key => $_path) {
            //Check the entity path is in watching paths
            if (strpos($classPath, realpath($_path)) === 0) {
                $inPaths = true;
            }
        }
        if ($inPaths) {
            $classAnnotations = $this->getContainer()->get('annotation_reader')->getClassAnnotations($class);
            if (!empty($classAnnotations)) {
                foreach ($classAnnotations as $key => $annot) {
                    if (!is_numeric($key)) {
                        continue;
                    }
                    $classAnnotations[get_class($annot)] = $annot;
                }
            }

            // Evaluate Entity annotation
            if (isset($classAnnotations['Victoire\Bundle\CoreBundle\Annotations\BusinessEntity'])) {
                /** @var BusinessEntity $annotationObj */
                $annotationObj = $classAnnotations['Victoire\Bundle\CoreBundle\Annotations\BusinessEntity'];
                $businessEntity = $this->createBusinessEntity(
                    $class->getName(),
                    $annotationObj,
                    $this->loadBusinessProperties($class)
                );
                $entityManager->persist($businessEntity);
            }
        }
        $entityManager->flush();
    }

    /**
     * load business properties from ReflectionClass.
     *
     * @return array
     **/
    protected function loadBusinessProperties(\ReflectionClass $class)
    {
        $reader = $this->getContainer()->get('annotation_reader');
        $businessProperties = [];
        $properties = $class->getProperties();
        $traits = $class->getTraits();
        $className = $class->getName();
        // if the class is translatable, then parse annotations on it's translation class
        if (array_key_exists(Translatable::class, $traits)) {
            $translation = new \ReflectionClass($className::getTranslationEntityClass());
            $translationProperties = $translation->getProperties();
            $properties = array_merge($properties, $translationProperties);
        }

        foreach ($properties as $property) {
            $annotations = $reader->getPropertyAnnotations($property);
            foreach ($annotations as $key => $annotationObj) {
                if ($annotationObj instanceof BusinessPropertyAnnotation && !in_array($class, $businessProperties)) {
                    $businessProperties[$property->name] = $annotationObj;
//                    foreach ($annotations[$key]->getTypes() as $type) {
//                        $businessProperties[$type][] = $property->name;
//                    }
                }
            }
        }
        // we load business properties of parents recursively
        // because they are defined by an annotation not by the property type(private, protected, public)
        $parentClass = $class->getParentClass();
        if ($parentClass) {
            //load parent properties recursively
            $parentProperties = $this->loadBusinessProperties(new \ReflectionClass($parentClass->getName()));
            foreach ($parentProperties as $propertyName => $parentProperty) {
                if (!array_key_exists($propertyName, $businessProperties)) {
                    $businessProperties[$propertyName] = $parentProperty;
                }
            }
//            foreach ($parentProperties as $key => $parentProperty) {
//                if (in_array($parentProperty, $businessProperties)) {
//                    //if parent and current have a same business property type we merge the properties and remove
//                    //duplicates if properties are the same;
//                    $businessProperties[$key] = array_unique(array_merge($parentProperty, $businessProperties[$key]));
//                } else {
//                    //else we had a business property type for the parent properties
//                    $businessProperties[$key] = $parentProperty;
//                }
//            }
        }

        return $businessProperties;
    }

    /**
     * @param $className
     * @param $annotationObj
     * @param $businessProperties
     *
     * @return ORMBusinessEntity
     */
    private function createBusinessEntity($className, $annotationObj, $businessProperties)
    {
        $businessEntity = new ORMBusinessEntity();
        $classNameArray = explode('\\', $className);
        $entityName = array_pop($classNameArray);
        $businessEntity->setName($entityName);
        $businessEntity->setClass($className);
        $businessEntity->setAvailableWidgets($annotationObj->getWidgets());
        //parse the array of the annotation reader
        foreach ($businessProperties as $propertyName => $property) {
            $businessProperty = new BusinessProperty();
            $businessProperty->setTypes($property->getTypes());
            $businessProperty->setBusinessEntity($businessEntity);
            $businessProperty->setName($propertyName);
        }
        return $businessEntity;
    }


}