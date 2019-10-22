<?php

namespace App\Command;

use App\Entity\IIIfManifest;
use App\ResourceSpace\ResourceSpace;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateIIIFManifestsCommand extends ContainerAwareCommand
{
    private $verbose;
    private $cantaloupeUrl;

    private $resourceSpace;
    private $imagehubData;

    private $serviceUrl;

    protected function configure()
    {
        $this
            ->setName('app:generate-iiif-manifests')
            ->setDescription('')
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbose = $input->getOption('verbose');

        // Make sure the service URL name ends with a trailing slash
        $this->serviceUrl = rtrim($this->getContainer()->getParameter('service_url'), '/') . '/';

        $this->cantaloupeUrl = $this->getContainer()->getParameter('cantaloupe_url');
        $this->resourceSpace = new ResourceSpace($this->getContainer());
        $resourceSpaceData = $this->resourceSpace->getCurrentResourceSpaceData();

        $this->imagehubData = array();

        $metadataFields = array('nl-titleartwork' => 'Title', 'sourceinvnr' => 'Object ID', 'description' => 'Description', 'publisher' => 'Credit Line');

        foreach($resourceSpaceData as $resourceId => $data) {
            $this->imagehubData[$resourceId] = array();
            $this->addCantaloupeData($resourceId);
            $this->imagehubData[$resourceId]['metadata'] = array();
            foreach($metadataFields as $field => $name) {
                $this->imagehubData[$resourceId]['metadata'][$name] = $data[$field];
            }
            $this->imagehubData[$resourceId]['related_records'] = explode(PHP_EOL, $data['relatedrecords']);
            $this->imagehubData[$resourceId]['manifest_id'] = $this->serviceUrl . 'kmska.be:' . $data['sourceinvnr'] . '/manifest.json';
            $this->imagehubData[$resourceId]['canvas_base'] = $this->serviceUrl . 'kmska.be:' . $data['sourceinvnr'];
        }

        $em = $this->getContainer()->get('doctrine')->getManager();
        $this->generateAndStoreManifests($em);
    }

    private function addCantaloupeData($resourceId)
    {
        try {
            $jsonData = file_get_contents($this->cantaloupeUrl . $resourceId . '.tif/info.json');
            $data = json_decode($jsonData);
            $this->imagehubData[$resourceId]['height'] = $data->height;
            $this->imagehubData[$resourceId]['width'] = $data->width;
            if($this->verbose) {
                echo 'Retrieved image ' . $resourceId . ' from Cantaloupe' . PHP_EOL;
//                $this->logger->info('Retrieved image ' . $resourceId . ' from Cantaloupe');
            }
        } catch(Exception $e) {
            echo $e->getMessage();
//            $this->logger->error($e->getMessage());
            // TODO proper error reporting
        }
    }

    private function generateAndStoreManifests(EntityManagerInterface $em)
    {
        $validate = $this->getContainer()->getParameter('validate_manifests');
        $validatorUrl = $this->getContainer()->getParameter('validator_url');

        // Top-level collection containing a link to all manifests
        $manifests = array();

        foreach($this->imagehubData as $resourceId => $data) {

            $label = null;
            $description = null;
            $attribution = null;

            // Fill in (multilingual) manifest data
            $manifestMetadata = array();
            foreach($data['metadata'] as $key => $metadata) {
/*                $arr = array();
                foreach($metadata as $language => $value) {
                    // Change nl into nl-BE, en into en-GB, etc.
                    if(array_key_exists($language, $this->localisations)) {
                        $language = $this->localisations[$language];
                    }
                    $arr[] = array(
                        '@language' => $language,
                        '@value'    => $value
                    );
                }*/
                // Grab the values for the top-level description, label and attribution
                if($key == 'Description') {
                    $description = $metadata;
                    // Description is not included in the metadata field
                    continue;
                } else if($key == 'Title') {
                    $label = $metadata;
                } else if($key == 'Credit Line') {
                    $attribution = $metadata;
                }
                $manifestMetadata[] = array(
                    'label' => $key,
                    'value' => $metadata
                );
            }

            // Generate the canvases
            $canvases = array();
            $index = 0;
            $startCanvas = null;
            $thumbnail = null;

            // Loop through all works related to this resource (including itself)
            foreach($data['related_records'] as $relatedRef) {

                // When the related resource ID is the ID of the resource we're currently processing,
                // we know that this canvas is in fact the main canvas.
                $isStartCanvas = $relatedRef == $resourceId;

                $index++;
                $canvasId = $data['canvas_base'] . '/canvas/' . $index . '.json';
                $serviceId = $this->serviceUrl . $relatedRef;
                if($isStartCanvas && $startCanvas == null) {
                    $startCanvas = $canvasId;
                    $thumbnail = $serviceId;
                }
                $service = array(
                    '@context' => 'http://iiif.io/api/image/2/context.json',
                    '@id'      => $serviceId,
                    'profile'  => 'http://iiif.io/api/image/2/level2.json'
                );
                $resource = array(
                    '@id'     => $this->cantaloupeUrl . $relatedRef . '.tif/full/full/0/default.jpg',
                    '@type'   => 'dctypes:Image',
                    'format'  => 'image/jpeg',
                    'service' => $service,
                    'height'  => $this->imagehubData[$relatedRef]['height'],
                    'width'   => $this->imagehubData[$relatedRef]['width']
                );
                $image = array(
                    '@context'   => 'http://iiif.io/api/presentation/2/context.json',
                    '@type'      => 'oa:Annotation',
                    '@id'        => $canvasId . '/image',
                    'motivation' => 'sc:painting',
                    'resource'   => $resource,
                    'on'         => $canvasId
                );
                $newCanvas = array(
                    '@id'    => $canvasId,
                    '@type'  => 'sc:Canvas',
                    'label'  => $relatedRef,
                    'height' => $this->imagehubData[$relatedRef]['height'],
                    'width'  => $this->imagehubData[$relatedRef]['width'],
                    'images' => array($image)
                );
                $canvases[] = $newCanvas;

/*                // Store the canvas in the database
                $canvasDocument = new Canvas();
                $canvasDocument->setCanvasId($canvasId);
                $canvasDocument->setData(json_encode($newCanvas));
                $dm->persist($canvasDocument);
*/
            }

            // Fill in sequence data
            if($startCanvas == null) {
                $manifestSequence = array(
                    '@type'       => 'sc:Sequence',
                    '@context'    => 'http://iiif.io/api/presentation/2/context.json',
                    'canvases'    => $canvases
                );
            } else {
                $manifestSequence = array(
                    '@type'       => 'sc:Sequence',
                    '@context'    => 'http://iiif.io/api/presentation/2/context.json',
                    'startCanvas' => $startCanvas,
                    'canvases'    => $canvases
                );
            }

            $manifestId = $data['manifest_id'];
            // Generate the whole manifest
            $manifest = array(
                '@context'         => 'http://iiif.io/api/presentation/2/context.json',
                '@type'            => 'sc:Manifest',
                '@id'              => $manifestId,
                'label'            => $label,
                'attribution'      => $attribution,
                'description'      => empty($description) ? 'n/a' : $description,
                'metadata'         => $manifestMetadata,
                'viewingDirection' => 'left-to-right',
                'viewingHint'      => 'individuals',
                'sequences'        => array($manifestSequence),
            );

            //TODO do we actually need a top-level manifest?
            // If so, we need to store the 'label' of each manifest separately and then do a SELECT to get all ID's and labels for the top-level manifest

            $qb = $em->createQueryBuilder();
            $query = $qb->delete(IIIfManifest::class, 'manifest')
                ->where('manifest.manifestId = :manif_id')
                ->setParameter('manif_id', $manifestId)
                ->getQuery();
            $query->execute();
            $em->flush();

            // Store the manifest in mongodb
            $manifestDocument = new IIIFManifest();
            $manifestDocument->setManifestId($manifestId);
            $manifestDocument->setData(json_encode($manifest));
            $em->persist($manifestDocument);
            $em->flush();


            // Validate the manifest
            // We can only pass a URL to the validator, so the manifest needs to be stored and served already before validation
            // If it does not pass validation, remove from the database
            $valid = true;
            if($validate) {
                $valid = $this->validateManifest($validatorUrl, $manifestId);
                if (!$valid) {
//                    $this->logger->error('Manifest ' . $manifestId . ' is not valid.');
                    echo 'Manifest ' . $manifestId . ' is not valid.' . PHP_EOL;
                    $em->remove($manifestDocument);
                    $em->flush();
                }
            }
            $em->clear();

            if($valid) {
                if($this->verbose) {
                    echo 'Generated manifest ' . $manifestId . ' for resource ' . $resourceId . PHP_EOL;
//                    $this->logger->info('Generated manifest ' . $manifestId . ' for data pid ' . $dataPid);
                }

                // Add to manifests array to add to the top-level collection
                $manifests[] = array(
                    '@id' => $manifestId,
                    '@type' => 'sc:Manifest',
                    'label' => $label
                );

                // Update the LIDO data to include the manifest and thumbnail
//                $this->addManifestAndThumbnailToLido($this->namespace, $dataPid, $manifestId, $thumbnail);
            }
        }



        // Generate the top-level collection and store it in mongoDB
        $collectionId = $this->serviceUrl . 'collection/top';
        $collection = array(
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            '@id' => $collectionId,
            '@type' => 'sc:Collection',
            'label' => 'Top Level Collection for Imagehub',
            'viewingHint' => 'top',
            'description' => 'This collection lists all the IIIF manifests available in this Imagehub instance',
            'manifests' => $manifests
        );


        $qb = $em->createQueryBuilder();
        $query = $qb->delete(IIIfManifest::class, 'manifest')
            ->where('manifest.manifestId = :manif_id')
            ->setParameter('manif_id', $collectionId)
            ->getQuery();
        $query->execute();
        $em->flush();


        $manifestDocument = new IIIFManifest();
        $manifestDocument->setManifestId($collectionId);
        $manifestDocument->setData(json_encode($collection));
        $em->persist($manifestDocument);
        $em->flush();

        $valid = true;
        if($validate) {
            $valid = $this->validateManifest($validatorUrl, $collectionId);
            if (!$valid) {
                echo 'Top-level collection ' . $collectionId . ' is not valid.' . PHP_EOL;
//                $this->logger->error('Top-level collection ' . $collectionId . ' is not valid.');
                $em->remove($manifestDocument);
                $em->flush();
            }
        }
        $em->clear();

        if($this->verbose) {
            if ($valid) {
                echo 'Created and stored top-level collection' . PHP_EOL;
//                $this->logger->info('Created and stored top-level collection');
            }
            echo 'Done, created and stored ' . count($manifests) . ' manifests.' . PHP_EOL;
//            $this->logger->info('Done, created and stored ' . $manifests . ' manifests.');
        }
    }

    private function validateManifest($validatorUrl, $manifestId)
    {
        $valid = true;
        try {
            $validatorJsonResult = file_get_contents($validatorUrl . $manifestId);
            $validatorResult = json_decode($validatorJsonResult);
            $valid = $validatorResult->okay == 1;
            if (!empty($validatorResult->warnings)) {
                foreach ($validatorResult->warnings as $warning) {
                    echo 'Manifest ' . $manifestId . ' warning: ' . $warning . PHP_EOL;
//                    $this->logger->warning('Manifest ' . $manifestId . ' warning: ' . $warning);
                }
            }
            if (!empty($validatorResult->error)) {
                if ($validatorResult->error != 'None') {
                    $valid = false;
                    echo 'Manifest ' . $manifestId . ' error: ' . $validatorResult->error . PHP_EOL;
//                    $this->logger->error('Manifest ' . $manifestId . ' error: ' . $validatorResult->error);
                }
            }
        } catch (Exception $e) {
            if($this->verbose) {
                echo 'Error validating manifest ' . $manifestId . ': ' . $e . PHP_EOL;
//                $this->logger->error('Error validating manifest ' . $manifestId . ': ' . $e);
            } else {
                echo 'Error validating manifest ' . $manifestId . ': ' . $e->getMessage() . PHP_EOL;
//                $this->logger->error('Error validating manifest ' . $manifestId . ': ' . $e->getMessage());
            }
        }
        return $valid;
    }
}
