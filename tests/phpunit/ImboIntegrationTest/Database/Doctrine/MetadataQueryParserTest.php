<?php
/**
 * This file is part of the Imbo package
 *
 * (c) Christer Edvartsen <cogo@starzinger.net>
 *
 * For the full copyright and license information, please view the LICENSE file that was
 * distributed with this source code.
 */

namespace ImboIntegrationTest\Database\Doctrine;

use Imbo\Database\Doctrine\MetadataQueryParser,
    Doctrine\DBAL\Query\QueryBuilder,
    Doctrine\DBAL\Configuration,
    Doctrine\DBAL\DriverManager,
    PDO;

/**
 * @covers Imbo\Database\Doctrine\MetadataQueryParser
 * @group integration
 * @group database
 * @group doctrine
 */
class MetadataQueryParserTest extends \PHPUnit_Framework_TestCase {
    /**
     * @var MetadataQueryParser
     */
    private $parser;

    /**
     * Set up the parser
     */
    public function setUp() {
        if (!class_exists('Doctrine\DBAL\Query\QueryBuilder')) {
            $this->markTestSkipped('Doctrine is required to run this test');
        }

        $connection = DriverManager::getConnection(
            array('pdo' => new PDO('sqlite::memory:')),
            new Configuration()
        );
        $this->queryBuilder = new QueryBuilder($connection);
        $this->queryBuilder->select('*')->from('image', 'i');

        $this->parser = new MetadataQueryParser();
    }

    /**
     * Tear down the parser
     */
    public function tearDown() {
        $this->queryBuilder = null;
        $this->parser = null;
    }

    /**
     * Data provider
     *
     * @return array[]
     */
    public function getMetadataQueries() {
        return array(
            'regular match' => array(
                array('field' => 'value'),
                'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` = ?',
            ),
            'regular match, implicit and' => array(
                array('field' => 'value', 'field2' => 'value2'),
                'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE (`m.field` = ?) AND (`m.field2` = ?)',
            ),
            'explicit and' => array(
                array('$and' => array(array('field' => 123))),
               'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` = ?',
            ),
            'explicit and, multiple fields' => array(
                array('$and' => array(array('field1' => 123), array('field2' => 456))),
               'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE (`m.field1` = ?) AND (`m.field2` = ?)',
            ),
            'or' => array(
                array('$or' => array(array('field' => 123), array('field' => 456))),
               'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE (`m.field` = ?) OR (`m.field` = ?)',
            ),
            'multiple and/or' => array(
                array('field1' => 'value', '$or' => array(array('field2' => 123), array('field2' => 456)), 'field3' => 789),
               'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE (`m.field1` = ?) AND ((`m.field2` = ?) OR (`m.field2` = ?)) AND (`m.field3` = ?)',
            ),
            'not equals' => array(
                array('field' => array('$ne' => 'value')),
                'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` <> ?',
            ),
            'greater than' => array(
                array('field' => array('$gt' => 123)),
                'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` > ?',
            ),
            'greather than or equal' => array(
                array('field' => array('$gte' => 123)),
                'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` >= ?',
            ),
            'less than' => array(
                array('field' => array('$lt' => 123)),
                'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` < ?',
            ),
            'less than or equal' => array(
                array('field' => array('$lte' => 123)),
                'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` <= ?',
            ),
            'in' => array(
                array('field' => array('$in' => array(1, 2, 3))),
                'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` IN (?)',
            ),
            'not in' => array(
                array('field' => array('$nin' => array(1, 2, 3))),
                'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` NOT IN (?)',
            ),
            'wildcard search' => array(
                array('field' => array('$wildcard' => '*value*')),
               'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE `m.field` LIKE ?',
            ),
            'complex query with many different operators' => array(
                array(
                    'field' => 'value',
                    'field2' => array(
                        '$in' => array(1, 2, 3),
                    ),
                    'field3' => array(
                        '$ne' => 'foo',
                    ),
                    '$or' => array(
                        array('field' => 'foo'),
                        array('field2' => array('$nin' => array(1, 2, 3))),
                        array('field3' => array(
                            '$wildcard' => '*hey*',
                        )),
                    ),
                ),
               'SELECT * FROM image i LEFT JOIN metadata m ON i.id = m.imageId WHERE (`m.field` = ?) AND (`m.field2` IN (?)) AND (`m.field3` <> ?) AND ((`m.field` = ?) OR (`m.field2` NOT IN (?)) OR (`m.field3` LIKE ?))',
            ),
        );
    }

    /**
     * @dataProvider getMetadataQueries
     */
    public function testCanParserQueries(array $metadataQuery, $sql) {
        $this->parser->parseMetadataQuery($metadataQuery, $this->queryBuilder);
        $this->assertSame($sql, (string) $this->queryBuilder, 'The query builder could not generate the correct SQL for the metadata query');
    }
}