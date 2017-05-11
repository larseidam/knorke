<?php

namespace Tests\Knorke;

use Knorke\Store\InMemoryStore;
use Knorke\Data\ParserFactory;
use PHPUnit\Framework\TestCase;
use Saft\Rdf\CommonNamespaces;
use Saft\Rdf\NodeFactoryImpl;
use Saft\Rdf\RdfHelpers;
use Saft\Rdf\Statement;
use Saft\Rdf\StatementFactoryImpl;
use Saft\Rdf\StatementIterator;
use Saft\Rdf\StatementIteratorFactoryImpl;
use Saft\Sparql\Query\QueryFactoryImpl;
use Saft\Sparql\Result\SetResult;

class UnitTestCase extends TestCase
{
    protected $commonNamespaces;

    /**
     * Contains an instance of the class to test.
     *
     * @var mixed
     */
    protected $fixture;

    protected $nodeFactory;
    protected $rdfHelpers;
    protected $statementFactory;

    protected $testGraph;

    public function setUp()
    {
        parent::setUp();

        $statementIteratorFactory = new StatementIteratorFactoryImpl();
        $statementFactory = new StatementFactoryImpl();

        $this->commonNamespaces = new CommonNamespaces();
        $this->rdfHelpers = new RdfHelpers();
        $this->nodeFactory = new NodeFactoryImpl($this->rdfHelpers);
        $this->queryFactory = new QueryFactoryImpl($this->rdfHelpers);
        $this->parserFactory = new ParserFactory(
            $this->nodeFactory,
            $statementFactory,
            $statementIteratorFactory,
            $this->rdfHelpers
        );
        $this->statementFactory = $statementFactory;
        $this->statementIteratorFactory = $statementIteratorFactory;
        $this->testGraph = $this->nodeFactory->createNamedNode('http://knorke/testgraph/');

        // basic in memory store
        $this->store = new InMemoryStore(
            $this->nodeFactory,
            $this->statementFactory,
            $this->queryFactory,
            $this->statementIteratorFactory,
            $this->commonNamespaces,
            $this->rdfHelpers
        );
    }

    /**
     * Checks two lists which implements \Iterator interface, if they contain the same Statement instances.
     * The checks will be executed using PHPUnit's assert functions.
     *
     * @param SetResult $expected
     * @param SetResult $actual
     */
    public function assertSetIteratorEquals(SetResult $expected, SetResult $actual)
    {
        $entriesToCheck = array();
        foreach ($expected as $entry) {
            // serialize entry and hash it afterwards to use it as key for $entriesToCheck array.
            // later on we only check the other list that each entry, serialized and hashed, has
            // its equal key in the list.
            // the structure of each entry is an associative array which contains Node instances.
            $entryString = '';
            foreach ($entry as $key => $nodeInstance) {
                if ($nodeInstance->isConcrete()) {
                    // build a string of all entries of $entry and generate a hash based on that later on.
                    $entryString = $nodeInstance->toNQuads();
                } else {
                    throw new \Exception('Non-concrete Node instance in SetResult instance found.');
                }
            }
            $entriesToCheck[hash('sha256', $entryString)] = $entry;
        }

        // contains a list of all entries, which were not found in $expected.
        $actualEntriesNotFound = array();
        $actualRealEntriesNotFound = array();
        foreach ($actual as $entry) {
            $entryString = '';
            foreach ($entry as $key => $nodeInstance) {
                if ($nodeInstance->isConcrete()) {
                    // build a string of all entries of $entry and generate a hash based on that later on.
                    $entryString = $nodeInstance->toNQuads();
                } else {
                    throw new \Exception('Non-concrete Node instance in SetResult instance found.');
                }
            }
            $entryHash = hash('sha256', $entryString);
            if (isset($entriesToCheck[$entryHash])) {
                // if entry was found, mark it.
                $entriesToCheck[$entryHash] = true;
            } else {
                // entry was not found
                $actualEntriesNotFound[] = $entryHash;
                $actualRealEntriesNotFound[] = $entry;
            }
        }
        $notCheckedEntries = array();
        // check that all entries from $expected were checked
        foreach ($entriesToCheck as $key => $value) {
            if (true !== $value) {
                $notCheckedEntries[] = $value;
            }
        }

        if (!empty($actualEntriesNotFound) || !empty($notCheckedEntries)) {
            $message = 'The StatementIterators are not equal.';
            if (!empty($actualEntriesNotFound)) {
                echo PHP_EOL . PHP_EOL . 'Not expected entries:' . PHP_EOL;
                print_r($actualRealEntriesNotFound);
                $message .= ' ' . count($actualEntriesNotFound) . ' Statements where not expected.';
            }
            if (!empty($notCheckedEntries)) {
                echo PHP_EOL . PHP_EOL . 'Not present entries:' . PHP_EOL;
                print_r($notCheckedEntries);
                $message .= ' ' . count($notCheckedEntries) . ' Statements where not present but expected.';
            }
            $this->fail($message);

        } elseif (0 == count($actualEntriesNotFound) && 0 == count($notCheckedEntries)) {
            $this->assertEquals($expected->getVariables(), $actual->getVariables());
        }
    }

    /**
     * Checks two lists which implements \Iterator interface, if they contain the same elements.
     * The checks will be executed using PHPUnit's assert functions.
     *
     * @param StatementIterator $expected
     * @param StatementIterator $actual
     * @param boolean $debug optional, default: false
     * @api
     * @since 0.1
     * @todo implement a more precise way to check blank nodes (currently we just count expected
     *       and actual numbers of statements with blank nodes)
     */
    public function assertStatementIteratorEquals(
        StatementIterator $expected,
        StatementIterator $actual,
        $debug = false
    ) {
        $entriesToCheck = array();
        $expectedStatementsWithBlankNodeCount = 0;

        foreach ($expected as $statement) {
            // serialize entry and hash it afterwards to use it as key for $entriesToCheck array.
            // later on we only check the other list that each entry, serialized and hashed, has
            // its equal key in the list.
            if (!$statement->isConcrete()) {
                $this->markTestIncomplete('Comparison of variable statements in iterators not yet implemented.');
            }
            if ($this->statementContainsNoBlankNodes($statement)) {
                $entriesToCheck[hash('sha256', $statement->toNQuads())] = false;
            } else {
                ++$expectedStatementsWithBlankNodeCount;
            }
        }

        // contains a list of all entries, which were not found in $expected.
        $actualEntriesNotFound = array();
        $notCheckedEntries = array();
        $foundEntries = array();
        $actualStatementsWithBlankNodeCount = 0;

        foreach ($actual as $statement) {
            if (!$statement->isConcrete()) {
                $this->markTestIncomplete("Comparison of variable statements in iterators not yet implemented.");
            }
            $statmentHash = hash('sha256', $statement->toNQuads());
            // statements without blank nodes
            if (isset($entriesToCheck[$statmentHash]) && $this->statementContainsNoBlankNodes($statement)) {
                // if entry was found, mark it.
                $entriesToCheck[$statmentHash] = true;
                $foundEntries[] = $statement;

            // handle statements with blank nodes separate because blanknode ID is random
            // and therefore gets lost when stored (usually)
            } elseif (false == $this->statementContainsNoBlankNodes($statement)) {
                ++$actualStatementsWithBlankNodeCount;

            // statement was not found
            } else {
                $actualEntriesNotFound[] = $statement;
                $notCheckedEntries[] = $statement;
            }
        }

        if (!empty($actualEntriesNotFound) || !empty($notCheckedEntries)) {
            $message = 'The StatementIterators are not equal.';
            if (!empty($actualEntriesNotFound)) {
                if ($debug) {
                    echo PHP_EOL . 'Following statements where not expected, but found: ';
                    var_dump($actualEntriesNotFound);
                }
                $message .= ' ' . count($actualEntriesNotFound) . ' Statements where not expected.';
            }
            if (!empty($notCheckedEntries)) {
                if ($debug) {
                    echo PHP_EOL . 'Following statements where not present, but expected: ';
                    var_dump($notCheckedEntries);
                }
                $message .= ' ' . count($notCheckedEntries) . ' Statements where not present but expected.';
            }
            $this->assertFalse(!empty($actualEntriesNotFound) || !empty($notCheckedEntries), $message);

        // compare count of statements with blank nodes
        } elseif ($expectedStatementsWithBlankNodeCount != $actualStatementsWithBlankNodeCount) {
            $this->assertFalse(
                true,
                'Some statements with blank nodes where not found. '
                    . 'Expected: ' . $expectedStatementsWithBlankNodeCount
                    . 'Actual: ' . $actualStatementsWithBlankNodeCount
            );

        } else {
            $this->assertTrue(true);
        }
    }

    protected function statementContainsNoBlankNodes(Statement $statement) : bool
    {
        return false == $statement->getSubject()->isBlank()
            && false == $statement->getObject()->isBlank();
    }
}
