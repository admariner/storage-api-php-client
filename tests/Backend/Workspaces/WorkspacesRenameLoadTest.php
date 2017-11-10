<?php
/**
 * @author Erik Zigo <erik.zigo@keboola.com>
 */
namespace Keboola\Test\Backend\Workspaces;

use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApi\ClientException;
use Keboola\Test\Backend\Workspaces\Backend\WorkspaceBackendFactory;

class WorkspaceRenameLoadTest extends WorkspacesTestCase
{
    public function testLoadIncremental()
    {
        $bucketId = $this->getTestBucketId(self::STAGE_IN);

        $workspaces = new Workspaces($this->_client);
        $workspace = $workspaces->createWorkspace();

        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);

        $importFile = __DIR__ . '/../../_data/languages-more-columns.csv';
        $tableId = $this->_client->createTable(
            $bucketId,
            'languagesDetails',
            new CsvFile($importFile),
            ['primaryKey' => 'Id']
        );

        // first load
        $options = [
            'input' => [
                [
                    'source' => $tableId,
                    'destination' => 'languagesDetails',
                    'whereColumn' => 'iso',
                    'whereValues' => ['dd', 'xx'],
                    'columns' => [
                        [
                            'source' => 'Id',
                            'destination' => 'primary',
                            'type' => 'integer',
                        ],
                        [
                            'source' => 'Name',
                            'destination' => 'title',
                            'type' => 'varchar',
                        ],
                    ],
                ],
            ],
        ];

        $workspaces->loadWorkspaceData($workspace['id'], $options);
        $this->assertEquals(2, $backend->countRows("languagesDetails"));

        foreach ($backend->fetchAll('languagesDetails', \PDO::FETCH_ASSOC) as $row) {
            $this->assertCount(2, $row);

            $this->assertArrayHasKey('primary', $row);
            $this->assertArrayHasKey('title', $row);

            $this->assertTrue(is_numeric($row['primary']));
            $this->assertFalse(is_numeric($row['title']));
        }

        // second load
        $options = [
            'input' => [
                [
                    'incremental' => true,
                    'source' => $tableId,
                    'destination' => 'languagesDetails',
                    'whereColumn' => 'iso',
                    'whereValues' => ['ff', 'xx'],
                    'columns' => [
                        [
                            'source' => 'Id',
                            'destination' => 'primary',
                            'type' => 'integer',
                        ],
                        [
                            'source' => 'Name',
                            'destination' => 'title',
                            'type' => 'varchar',
                        ],
                    ],
                ],
            ],
        ];

        $workspaces->loadWorkspaceData($workspace['id'], $options);
        $this->assertEquals(5, $backend->countRows("languagesDetails"));

        foreach ($backend->fetchAll('languagesDetails', \PDO::FETCH_ASSOC) as $row) {
            $this->assertCount(2, $row);

            $this->assertArrayHasKey('primary', $row);
            $this->assertArrayHasKey('title', $row);

            $this->assertTrue(is_numeric($row['primary']));
            $this->assertFalse(is_numeric($row['title']));
        }
    }

    public function testDottedDestination()
    {
        $workspaces = new Workspaces($this->_client);
        $workspace = $workspaces->createWorkspace();

        // Create a table of sample data
        $importFile = __DIR__ . '/../../_data/languages.csv';
        $tableId = $this->_client->createTable(
            $this->getTestBucketId(self::STAGE_IN),
            'languages_dotted',
            new CsvFile($importFile)
        );

        $workspaces->loadWorkspaceData($workspace['id'], [
            "input" => [
                [
                    "source" => $tableId,
                    "destination" => "dotted.destination",
                    "columns" => [
                        [
                            "source" => "id",
                            "destination" => "lang.id",
                            "type" => "INTEGER",
                        ],
                        [
                            "source" => "name",
                            "destination" => "lang.name",
                            "type" => "VARCHAR",
                        ]
                    ]
                ]
            ]
        ]);

        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);
        // let's try to delete some columns
        $tables = $backend->getTables();
        $this->assertCount(1, $tables);
        $this->assertEquals('dotted.destination', $tables[0]);

        foreach ($backend->fetchAll('dotted.destination', \PDO::FETCH_ASSOC) as $row) {
            $this->assertCount(2, $row);

            $this->assertArrayHasKey('lang.id', $row);
            $this->assertArrayHasKey('lang.name', $row);
            break;
        }
    }

    public function testIncrementalAdditionalColumns()
    {
        $workspaces = new Workspaces($this->_client);
        $workspace = $workspaces->createWorkspace();
        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);

        $importFile = __DIR__ . '/../../_data/languages.csv';
        $tableId = $this->_client->createTable(
            $this->getTestBucketId(self::STAGE_IN),
            'languages',
            new CsvFile($importFile)
        );

        // first load
        $options = [
            'input' => [
                [
                    'source' => $tableId,
                    'destination' => 'languages',
                    'columns' => [
                        [
                            'source' => 'id',
                            'destination' => 'LangId',
                            'type' => 'integer',
                        ],
                        [
                            'source' => 'name',
                            'destination' => 'LangName',
                            'type' => 'varchar',
                        ],
                    ],
                ],
            ],
        ];

        $workspaces->loadWorkspaceData($workspace['id'], $options);
        $this->assertEquals(5, $backend->countRows("languages"));

        $this->_client->addTableColumn($tableId, 'test');

        // second load with additional columns
        $options = [
            'input' => [
                [
                    'incremental' => true,
                    'source' => $tableId,
                    'destination' => 'languages',
                    'columns' => [
                        [
                            'source' => 'id',
                            'destination' => 'LangId',
                            'type' => 'integer',
                        ],
                        [
                            'source' => 'name',
                            'destination' => 'LangName',
                            'type' => 'varchar',
                        ],
                        [
                            'source' => 'test',
                            'destination' => 'LangTest',
                            'type' => 'varchar',
                        ],
                    ],
                ],
            ],
        ];

        try {
            $workspaces->loadWorkspaceData($workspace['id'], $options);
            $this->fail('Workspace should not be loaded');
        } catch (ClientException $e) {
            $this->assertEquals('workspace.columnsNotMatch', $e->getStringCode());
            $this->assertContains('columns are missing in workspace table', $e->getMessage());
            $this->assertContains('languages', $e->getMessage());
        }
    }

    public function testIncrementalMissingColumns()
    {
        $workspaces = new Workspaces($this->_client);
        $workspace = $workspaces->createWorkspace();
        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);

        $importFile = __DIR__ . '/../../_data/languages.csv';
        $tableId = $this->_client->createTable(
            $this->getTestBucketId(self::STAGE_IN),
            'languages',
            new CsvFile($importFile)
        );

        // first load
        $options = [
            'input' => [
                [
                    'source' => $tableId,
                    'destination' => 'languages',
                    'columns' => [
                        [
                            'source' => 'id',
                            'destination' => 'LangId',
                            'type' => 'integer',
                        ],
                        [
                            'source' => 'name',
                            'destination' => 'LangName',
                            'type' => 'varchar',
                        ],
                    ],
                ],
            ],
        ];

        $workspaces->loadWorkspaceData($workspace['id'], $options);
        $this->assertEquals(5, $backend->countRows("languages"));

        $this->_client->deleteTableColumn($tableId, 'name');

        // second load with additional columns
        $options = [
            'input' => [
                [
                    'incremental' => true,
                    'source' => $tableId,
                    'destination' => 'languages',
                    'columns' => [
                        [
                            'source' => 'id',
                            'destination' => 'LangId',
                            'type' => 'integer',
                        ],
                    ],
                ],
            ],
        ];

        try {
            $workspaces->loadWorkspaceData($workspace['id'], $options);
            $this->fail('Workspace should not be loaded');
        } catch (ClientException $e) {
            $this->assertEquals('workspace.columnsNotMatch', $e->getStringCode());
            $this->assertContains('columns are missing in source table', $e->getMessage());
            $this->assertContains($tableId, $e->getMessage());
        }
    }

    /**
     * @dataProvider columnsErrorDefinitions
     */
    public function testIncrementalDataTypesDiff($table, $firstLoadDataColumns, $secondLoadDataColumns)
    {
        $workspaces = new Workspaces($this->_client);
        $workspace = $workspaces->createWorkspace();

        $importFile = __DIR__ . "/../../_data/$table.csv";

        $tableId = $this->_client->createTable(
            $this->getTestBucketId(self::STAGE_IN),
            $table,
            new CsvFile($importFile)
        );

        // first load
        $options = [
            'input' => [
                [
                    'source' => $tableId,
                    'destination' => $table,
                    'columns' => $firstLoadDataColumns,
                ],
            ],
        ];

        $workspaces->loadWorkspaceData($workspace['id'], $options);

        // second load - incremental
        $options = [
            'input' => [
                [
                    'incremental' => true,
                    'source' => $tableId,
                    'destination' => $table,
                    'columns' => $secondLoadDataColumns,
                ],
            ],
        ];

        try {
            $workspaces->loadWorkspaceData($workspace['id'], $options);
            $this->fail('Incremental load with different datatypes should fail');
        } catch (ClientException $e) {
            $this->assertEquals('workspace.columnsTypesNotMatch', $e->getStringCode());
            $this->assertContains('Different mapping between', $e->getMessage());
        }
    }

    /**
     * @dataProvider validColumnsDefinitions
     * @param $columnsDefinition
     */
    public function testDataTypes($columnsDefinition)
    {
        $workspaces = new Workspaces($this->_client);
        $workspace = $workspaces->createWorkspace();
        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);

        $importFile = __DIR__ . '/../../_data/languages.camel-case-columns.csv';
        $tableId = $this->_client->createTable(
            $this->getTestBucketId(self::STAGE_IN),
            'languages',
            new CsvFile($importFile)
        );

        $options = array('input' => [
            [
                'source' => $tableId,
                'destination' => 'datatype_Test',
                'columns' => $columnsDefinition
            ]
        ]);

        $workspaces->loadWorkspaceData($workspace['id'], $options);

        //check to make sure the columns have the right types
        $columnInfo = $backend->describeTableColumns($backend->toIdentifier('datatype_Test'));
        $this->assertCount(2, $columnInfo);
        if ($workspace['connection']['backend'] === $this::BACKEND_SNOWFLAKE) {
            $this->assertEquals("LangId", $columnInfo[0]['name']);
            $this->assertEquals("NUMBER(38,0)", $columnInfo[0]['type']);
            $this->assertEquals("LangName", $columnInfo[1]['name']);
            $this->assertEquals("VARCHAR(50)", $columnInfo[1]['type']);
        }
        if ($workspace['connection']['backend'] === $this::BACKEND_REDSHIFT) {
            $this->assertEquals("int4", $columnInfo['id']['DATA_TYPE']);
            $this->assertEquals("lzo", $columnInfo['id']['COMPRESSION']);
            $this->assertEquals("varchar", $columnInfo['name']['DATA_TYPE']);
            $this->assertEquals(50, $columnInfo['name']['LENGTH']);
            $this->assertEquals("lzo", $columnInfo['name']['COMPRESSION']);
        }
    }

    public function columnsErrorDefinitions()
    {
        return [
            [
                'languages',
                [
                    [
                        'source' =>  'name',
                        'destination' =>  'LangName',
                        'type' => 'VARCHAR',
                        'convertEmptyValuesToNull' => false,
                    ],
                ],
                [
                    [
                        'source' =>  'name',
                        'destination' =>  'LangName',
                        'type' => 'CHARACTER',
                        'convertEmptyValuesToNull' => false,
                    ],
                ],
            ],
        ];
    }

    public function validColumnsDefinitions()
    {
        return [
            [
                [
                    [
                        'source' => 'Id',
                        'destination' => 'LangId',
                        'type' => 'INTEGER',
                    ],
                    [
                        'source' => 'Name',
                        'destination' => 'LangName',
                        'type' => 'VARCHAR',
                        'length' => '50',
                    ],
                ]
            ],
        ];
    }
}
