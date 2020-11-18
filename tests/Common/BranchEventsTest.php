<?php

namespace Keboola\Test\Common;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\DevBranches;
use Keboola\Test\StorageApiTestCase;
use Keboola\StorageApi\Event;

class BranchEventsTest extends StorageApiTestCase
{
    public function testDevBranchEventCreated()
    {
        $providedToken = $this->_client->verifyToken();
        $devBranch = new DevBranches($this->_client);
        // cleanup
        $branchesList = $devBranch->listBranches();
        $branchName = __CLASS__ . '\\' . $this->getName() . '\\' . $providedToken['id'];
        $branchesCreatedByThisTestMethod = array_filter(
            $branchesList,
            function ($branch) use ($branchName) {
                return strpos($branch['name'], $branchName) === 0;
            }
        );
        foreach ($branchesCreatedByThisTestMethod as $branch) {
            try {
                $this->_client->dropBucket('in.c-dev-branch-' . $branch['id']);
            } catch (ClientException $e) {
            }

            $devBranch->deleteBranch($branch['id']);
        }

        // event for main branch dispatched
        $branch = $devBranch->createBranch($branchName);
        $configurationId = 'dev-branch-' . $branch['id'];

        $branchAwareClient = $this->getBranchAwareDefaultClient($branch['id']);

        $branchComponents = new \Keboola\StorageApi\Components($branchAwareClient);
        $config = (new \Keboola\StorageApi\Options\Components\Configuration())
            ->setComponentId('transformation')
            ->setConfigurationId($configurationId)
            ->setName('Dev Branch 1')
            ->setDescription('Configuration created');

        // event for development branch dispatched
        $branchComponents->addConfiguration($config);


        // create dummy config to test only one event return from $branchAwareClient
        $dummyBranch = $devBranch->createBranch($branchName . '-dummy');
        $dummyBranchAwareClient = $this->getBranchAwareDefaultClient($dummyBranch['id']);
        $dummyConfigurationId = 'dummy-dev-branch-' . $branch['id'];

        $dummyBranchComponents = new \Keboola\StorageApi\Components($dummyBranchAwareClient);
        $config = (new \Keboola\StorageApi\Options\Components\Configuration())
            ->setComponentId('transformation')
            ->setConfigurationId($dummyConfigurationId)
            ->setName('Dummy Dev Branch 1')
            ->setDescription('Configuration created');

        // event for dummy branch dispatched
        $dummyBranchComponents->addConfiguration($config);

        // There could be more than one event because for example bucket events are also returned
        // Test if return only one event created before for branch
        $branchAwareEvents = $this->waitForListEvents(
            $branchAwareClient,
            'event:storage.componentConfigurationCreated'
        );

        $this->assertCount(1, $branchAwareEvents);

        // test allowed non branch aware event - create bucket detail event in main branch
        $testBucketId = $this->_client->createBucket($configurationId, self::STAGE_IN);

        // event about bucket create should be return from branch aware event list
        $bucketsListedEvents = $this->waitForListEvents(
            $branchAwareClient,
            'objectId:' . $testBucketId
        );
        $this->assertCount(1, $bucketsListedEvents);
        $this->assertSame('storage.bucketCreated', $bucketsListedEvents[0]['event']);

        // check if there no exist componentConfigurationCreated event for main branch
        // to validate only main branch events will be returned
        $componentConfigCreateEvents = $this->_client->listEvents([
            'q' => 'objectId:' . $configurationId,
        ]);
        $this->assertCount(0, $componentConfigCreateEvents);

        $clientEventList = $this->_client->listEvents([
            'q' => 'idBranch:' . $branch['id']
        ]);
        $this->assertCount(0, $clientEventList);

        $bucketClientEventList = $this->_client->listEvents([
            'q' => 'objectId:' . $testBucketId
        ]);
        $this->assertCount(1, $bucketClientEventList);

        $this->assertTrue(count($this->_client->listEvents()) > 1);
    }

    private function waitForListEvents(Client $client, $query)
    {
        sleep(2); // wait for ES refresh
        $tries = 0;
        while (true) {
            $list = $client->listEvents([
                'q' => $query,
            ]);
            if (count($list) > 0) {
                return $list;
            }
            if ($tries > 4) {
                throw new \Exception('Max tries exceeded.');
            }
            $tries++;
            sleep(pow(2, $tries));
        }
    }
}
