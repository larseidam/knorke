<?php

namespace Knorke;

use Knorke\Exception\KnorkeException;
use Saft\Rdf\CommonNamespaces;
use Saft\Rdf\NodeUtils;
use Saft\Store\Store;

class StatisticValue
{
    protected $commonNamespaces;
    protected $mapping;
    protected $store;

    /**
     * @param Store $store
     * @param CommonNamespaces $commonNamespaces
     * @param NodeUtils $nodeUtils
     * @param array $mapping
     */
    public function __construct(
        Store $store,
        CommonNamespaces $commonNamespaces,
        NodeUtils $nodeUtils,
        array $mapping
    ) {
        $this->commonNamespaces = $commonNamespaces;
        $this->mapping = $mapping;
        $this->nodeUtils = $nodeUtils;
        $this->store = $store;
    }

    /**
     * Computes all depending values based on the given $mapping of non-depending values.
     *
     * @return array Complete mapping with computed values.
     * @throws KnorkeException if non-depending values have no mapping
     */
    public function compute()
    {
        $computedValues = array();

        // gather all SPO for StasticValue instances
        $statisticValueResult = $this->store->query(
            'SELECT * WHERE {
                ?s ?p ?o.
                ?s rdf:type kno:StatisticValue .
            }'
        );

        // collect subjects of all statistic value instances
        // create datablank instances for each statistic value for easier usage later on
        $statisticValues = array();
        foreach ($statisticValueResult as $entry) {
            $subjectUri = $entry['s']->getUri();
            if (false == isset($statisticValues[$subjectUri])) {
                $statisticValues[$subjectUri] = new DataBlank($this->commonNamespaces, $this->nodeUtils);
                $statisticValues[$subjectUri]->initBySetResult($statisticValueResult, $subjectUri);
            }
        }

        // check that all non-depending values were defined
        foreach ($statisticValues as $uri => $statisticValue) {
            // check for values which have no computationOrder property but are part of the mapping
            if (false === isset($statisticValue['kno:computationOrder'])
                && false === isset($this->mapping[$this->commonNamespaces->shortenUri($uri)])
                && false === isset($this->mapping[$this->commonNamespaces->extendUri($uri)])) {
                $e = new KnorkeException('Statistic value ' . $uri . ' is non-depending, but has no mapping.');
                $e->setPayload($statisticValue);
                throw $e;
            }
        }

        // compute computationOrder for each statistical value
        $statisticalValuesWithCompOrder = array();
        foreach ($statisticValues as $uri => $statisticValue) {
            if (isset($statisticValue['kno:computationOrder'])) {
                $statisticalValuesWithCompOrder[$this->commonNamespaces->extendUri($uri)] =
                    $this->getComputationOrderFor($uri, $statisticValues);
            }
        }

        // extend all URI keys if neccessary
        $computedValues = array();
        foreach ($this->mapping as $uri => $value) {
            $computedValues[$this->commonNamespaces->extendUri($uri)] = $value;
        }

        // go through all statistic value instances with computationOrder property and compute related values
        foreach ($statisticalValuesWithCompOrder as $uri => $computationOrder) {
            // assumption: properties are something like "kno:_1" and ordered, therefore we ignore properties later on
            // store computed value for statisticValue instance
            $computedValues[$this->commonNamespaces->extendUri($uri)] = $this->executeComputationOrder(
                $computationOrder,              // rule how to compute
                $computedValues,                // already computed stuff from before
                $statisticalValuesWithCompOrder // computation order per statistical value URI
            );
        }

        return $computedValues;
    }

    /**
     * @param string|float $value1
     * @param string $operation Either +, -, * or /
     * @param float $value1
     */
    public function computeValue($value1, $operation, $value2)
    {
        // if $value1 is a string, we assume its a date like '2017-01-01'
        if (is_string($value1) && is_numeric($value2)) {
            $dateTime = $value1 . ' 00:00:00';
            $timestamp = strtotime($dateTime);

            // stop here, if value2 is crap
            $value2 = (int)$value2;
            if (0 == $value2) {
                return null;
            }

            // $value2 is the number of days we go forward or backward from the given date
            switch ($operation) {
                case '+': return date('Y-m-d', ($timestamp+(86400*$value2)));
                case '-': return date('Y-m-d', ($timestamp-(86400*$value2)));
                default: return null;
            }

        // if $value1 amd $value2 are strings
        } elseif (is_string($value1) && is_string($value2)) {
            $dateTime1 = $value1 . ' 00:00:00';
            $timestamp1 = strtotime($dateTime1);

            $dateTime2 = $value2 . ' 00:00:00';
            $timestamp2 = strtotime($dateTime2);

            // operation needs to be MINUS, so we remove the second timestamp from the first
            // and will receive the number of days as difference
            switch ($operation) {
                case '-': return ($timestamp1-$timestamp2)/(60*60*24);
                default: return null;
            }

        // value1 and value2 are both floats and can therefore be computed directly
        } else {
            switch($operation) {
                case '+': return $value1 + (float)$value2;
                case '*': return $value1 * (float)$value2;
                case '-': return $value1 - (float)$value2;
                case '/': return $value1 / (float)$value2;
                default: return null;
            }
        }
    }

    /**
     * @param array $computationOrder Rules to compute one statistical value.
     * @param array $computedValues Array with URI as key and according computed value of already computed values.
     * @param array $statisticalValuesWithCompOrder
     * @return float if computation works well
     * @throws KnorkeException if invalide rule was detected.
     * @todo handle the case that a required value is not available yet
     */
    public function executeComputationOrder(
        array $computationOrder,
        array $computedValues,
        array $statisticalValuesWithCompOrder
    ) {
        $lastComputedValue = null;

        foreach ($computationOrder as $computationRule) {
            $value1 = null;
            $value2 = null;
            $operation = null;

            // ROUNDUP: if its a float, always round to the next higher number (e.g. 1.1 => 2)
            if ('ROUNDUP' == $computationRule) {
                $precision = 0;
                $fig = (int)str_pad('1', $precision, '0');
                $lastComputedValue = (ceil($lastComputedValue * $fig) / $fig);

            // MAX(result,..): checks result and decides to keep result or to use the alternative, if its higher
            } elseif ('MAX(result' == substr($computationRule, 0, 10)) {
                // get max alternative
                preg_match('/MAX\(result,([0-9\s]+)\)/', $computationRule, $maxMatches);
                // use alternative over last computed value, if alternative is heigher
                if (isset($maxMatches[1]) && $maxMatches[1] > $lastComputedValue) {
                    $lastComputedValue = $maxMatches[1];
                }

            // IF clause: set value depending on an if-clause, e.g. IF([stat:1] > 0, 1, 0)
            // TODO implement gathering referenced value, if not computed yet
            } elseif (preg_match('/IF\(\[(.*)\]\s*([>|<])\s*([0-9]+),\s*([0-9]+),\s*([0-9]+)\)/', $computationRule, $ifMatch)
                && isset($ifMatch[1])) {
                $statisticValueUri = $this->commonNamespaces->extendUri($ifMatch[1]); // e.g. stat:2
                $ifOperation = $ifMatch[2];                                           // e.g. >
                $ifConstraintValue = (float)$ifMatch[3];                              // e.g. 0
                $ifValueOdd = $ifMatch[4];                                            // e.g. 1 (if true)
                $ifValueEven = $ifMatch[5];                                           // e.g. 0 (if false)

                // <
                if ('<' == $ifOperation && $computedValues[$statisticValueUri] < $ifConstraintValue) {
                    $lastComputedValue = (float)$ifValueOdd;
                // >
                } elseif ('>' == $ifOperation && $computedValues[$statisticValueUri] > $ifConstraintValue) {
                    $lastComputedValue = (float)$ifValueOdd;
                // =
                } else {
                    $lastComputedValue = (float)$ifValueEven;
                }

            // Reuse existing value
            // TODO implement gathering referenced value, if not computed yet
            } elseif (preg_match('/^\[(.*?)\]$/', $computationRule, $reuseMatch) && isset($reuseMatch[1])) {
                $lastComputedValue = $computedValues[$reuseMatch[1]];

            // parse and handle rule
            } else {
                preg_match('/\[(.*?)\]([*\/+-]{1})(.*)/', $computationRule, $doubleValueMatch);
                preg_match('/^([*|\/|+|-])(.*)/', $computationRule, $singleValueMatch);

                /*
                 * found match for 2 values with an operation to compute (like a+1). can only be as the first entry
                 */
                if (isset($doubleValueMatch[1]) && null == $lastComputedValue) {
                    $statisticValue1Uri = $this->commonNamespaces->extendUri($doubleValueMatch[1]);

                    if (isset($computedValues[$statisticValue1Uri])) {
                        $value1 = $computedValues[$statisticValue1Uri];
                    } elseif (isset($statisticalValuesWithCompOrder[$statisticValue1Uri])) {
                        // get value because it wasn't computed yet
                        $value1 = $this->executeComputationOrder(
                            $statisticalValuesWithCompOrder[$statisticValue1Uri],
                            $computedValues,
                            $statisticalValuesWithCompOrder
                        );
                    } else {
                        $e = new KnorkeException('Parameter computation order is undefined.');
                        $e->setPayload(array(
                            'array_with_comp_order' => $statisticalValuesWithCompOrder,
                            'key_to_access_array' => $statisticValue1Uri
                        ));
                        throw $e;
                    }

                    $operation = $doubleValueMatch[2];
                    $value2 = $doubleValueMatch[3];

                /*
                 * found match for 1 value with 1 operation to compute (like +2).
                 * here we use the result of the computation last round as value1
                 */
                } elseif (isset($singleValueMatch[1]) && null !== $lastComputedValue) {
                    $value1 = $lastComputedValue;
                    $operation = $singleValueMatch[1];
                    $value2 = $singleValueMatch[2];
                }

                // if value2 is not a number, assume its an URI
                if (false === is_numeric($value2) && null !== $value2) {
                    $value2 = $this->commonNamespaces->extendUri($value2);

                    // get value because it wasn't computed yet
                    if (false == isset($computedValues[$value2])) {
                        $value2 = $this->executeComputationOrder(
                            $statisticalValuesWithCompOrder[$value2],
                            $computedValues,
                            $statisticalValuesWithCompOrder
                        );
                    } else {
                        $value2 = $computedValues[$value2];
                    }
                }

                // computation
                $lastComputedValue = $this->computeValue($value1, $operation, $value2);
            }

            if (null !== $lastComputedValue) continue;

            // if we reach this here, something went wrong. so always execute continue after you are "finished" with a
            // computation step to go to the next rule.

            /*
             * invalid rule found
             */
            $e = new KnorkeException(
                'Invalid computationRule found or you tried to use 2 value computation but had a result already.'
            );
            $e->setPayload(array(
                'computed_values' => $computedValues,
                'computation_rule' => $computationRule,
                'last_computed_value' => $lastComputedValue,
                'store' => $this->store,
            ));
            throw $e;
        }

        return $lastComputedValue;
    }

    /**
     * @param string $statisticValueUri
     * @param array $statisticValues Array of arrays which container references (kno:computationOrder) to blank nodes.
     * @return null|array Array if an order was found, null otherwise.
     */
    public function getComputationOrderFor($statisticValueUri, array $statisticValues)
    {
        foreach ($statisticValues as $uri => $value) {
            if ($uri == $statisticValueUri) {
                $result = $this->store->query(
                    'SELECT * WHERE {'. $value['kno:computationOrder'] .' ?p ?o.}'
                );
                $computationOrderBlank = new DataBlank(
                    $this->commonNamespaces,
                    $this->nodeUtils,
                    array(
                        'add_internal_data_fields' => false
                    )
                );
                $computationOrderBlank->initBySetResult($result, $value['kno:computationOrder']);

                // order entries by key
                $computationOrder = $computationOrderBlank->getArrayCopy();
                ksort($computationOrder);

                // extend all URIs used
                foreach ($computationOrder as $key => $string) {
                    unset($computationOrder[$key]);
                    $computationOrder[$key] = $this->commonNamespaces->extendUri($string);
                }

                return $computationOrder;
            }
        }

        return null;
    }
}
