<?php

namespace Iplox\Restful;

use Iplox\AbstractModule;
use Iplox\BaseController;
use Iplox\Config;
use Iplox\Http\Response;
use Iplox\Http\StatusCode;
use Doctrine\DBAL\Types\Type;

class DbalReadyController extends BaseController
{
    protected $module;
    protected $request;
    protected $config;
    protected $dbConn;
    protected $queryBuilder;

    public $tableName;
    public $tableAlias;
    public $tableRelations;
    public $queryFilters;
    public $exclusions;

    public $maxResultsCount = 20;

    public function __construct(Config $cfg, AbstractModule $mod){
        parent::__construct($cfg, $mod);

        $this->config = $cfg;
        $this->module = $mod;
        $this->dbConn = $mod->dbConn;
        $this->queryBuilder = $mod->queryBuilder;

        $this->request = $this->module->request;
        $this->contentType = 'application/json';
    }

    public function getListQueryBuilder($table = null, $alias = null, $filters = [], $relations = [], $exclusions = [])
    {
        $params = $this->module->request->params;
        $qb = $this->queryBuilder;
        $table = empty($table) ? $this->tableName : $table;
        $alias = empty($alias) ? $this->tableAlias : $alias;
        $filters = empty($filters) ? $this->queryFilters : $filters;
        $relations = empty($relations) ? $this->tableRelations : $relations;
        $exclusions = empty($exclusions) ? $this->exclusions : $exclusions;

        $qb->from($table, $alias);

        //Fields selection. Only support '=' operations. Pending to implement >=, <=, <>, != comparat ions.
        if (array_key_exists('fields', $params) && !empty($params['fields'])) {
            $fields = preg_split('/,/', $params['fields']);
            call_user_func_array([$qb, 'select'], $fields);
        } else {
            $qb->select('*');
        }

        //Sorting. Still do not detect if ordering in desc or asc.
        if (array_key_exists('sort', $params) && !empty($params['sort'])) {
            $qb->orderBy($this->quoteCommaSeparated($params['sort']));
        }

        //Limit.
        if (array_key_exists('limit', $params) && !empty($params['limit'])) {
            $qb->setMaxResults($params['limit']);
        } else {
            $qb->setMaxResults(($this->maxResultsCount ? $this->maxResultsCount : 20));
        }

        //Offset.
        if (array_key_exists('offset', $params) && !empty($params['offset'])) {
            $qb->setFirstResult($params['offset']);
        }

        //Filtering
        if (array_key_exists('include', $params) && !empty($params['include'])) {
            $includes = preg_split('/,/', $params['include']);
            if (count($includes) > 0) {
                $filterList = [];
                foreach ($filters as &$f) {
                    array_push($filterList, $alias . "_" . $f);
                }

                foreach ($includes as $inc) {
                    if (array_key_exists($inc, $relations)) {
                        foreach ($relations[$inc]['filterFields'] as $f) {
                            array_push($filterList, $relations[$inc]['tableAlias'] . "_" . $f);
                        }
                        $qb->join($relations[$inc]['toAlias'], $inc, $relations[$inc]['tableAlias'], $relations[$inc]['onCondition']);
                    }
                }
                $filters = $filterList;
            }
        }

        foreach ($params as $fname => $fvalue) {
            if (in_array($fname, $filters)) {
                $fname = preg_replace('/_/', '.', $fname, 1);
                if (preg_match('/\,/', trim($fvalue, ',')) > 0) {
                    $qb->andWhere("$fname IN (" . $this->quoteCommaSeparated($fvalue) . ")");
                } else {
                    $qb->andWhere("$fname = " . $this->dbConn->quote($fvalue));
                }
            }
        }

        return $qb;
    }

    public function getOneQueryBuilder($id, $table = null, $alias = null, $filters = [], $relations = [], $exclusions = [])
    {
        $params = $this->module->request->params;
        $qb = $this->queryBuilder;
        $table = empty($table) ? $this->tableName : $table;
        $alias = empty($alias) ? $this->tableAlias : $alias;
        $filters = empty($filters) ? $this->queryFilters : $filters;
        $relations = empty($relations) ? $this->tableRelations : $relations;
        $exclusions = empty($exclusions) ? $this->exclusions : $exclusions;

        $qb->from($table, $alias);

        //Fields selection. Only support '=' operations. Pending to implement >=, <=, <>, != comparat ions.
        if(array_key_exists('fields', $params) && !empty($params['fields'])){
            $fields = preg_split('/,/', $params['fields']);
            call_user_func_array([$qb, 'select'], $fields);
        } else {
            $qb->select('*');
        }

        //Filtering
        if(array_key_exists('include', $params) && !empty($params['include'])) {
            $includes = preg_split('/,/', $params['include']);
            if(count($includes) > 0) {
                $filterList = [];
                foreach ($filters as &$f) {
                    array_push($filterList,  $alias."_".$f);
                }

                foreach ($includes as $inc) {
                    if (array_key_exists($inc, $relations)) {
                        foreach ($relations[$inc]['filterFields'] as $f) {
                            array_push($filterList, $relations[$inc]['tableAlias'] . "_" . $f);
                        }
                        $qb->join($relations[$inc]['toAlias'], $inc, $relations[$inc]['tableAlias'], $relations[$inc]['onCondition']);
                    }
                }
                $filters = $filterList;
            }
        }

        $qb->where('id = :id');
        $qb->setParameter('id', $id, \PDO::PARAM_INT);

        return $qb;
    }

    public function addExclusion($fields, $tableAlias = null)
    {
        foreach($fields as $f) {
            array_push($this->exclusions, $tableAlias.'.'.$f);
        }
    }

    public function addTableRelation($tableName, $tableAlias, $toAlias, $onCondition, $filterFields)
    {
        if(empty($this->tableRelations)){
            $this->tableRelations = [];
        }

        $this->tableRelations[$tableName] = [
            'tableName' => $tableName,
            'tableAlias' => $tableAlias,
            'toAlias' => $toAlias,
            'onCondition' => $onCondition,
            'filterFields' => $filterFields,
        ];
    }

    public function quoteCommaSeparated($string)
    {
        $values = preg_split('/,/', trim($string, ','));

        $quote = '';
        foreach($values as $v){
            $quote .= ',' . $this->dbConn->quote($v);
        }
        $quote = trim($quote, ",");
        return $quote;
    }

    public function quoteArrayItems($values)
    {
        $quoted = [];
        foreach($values as $v){
            array_push($quoted, $this->dbConn->quote($v));
        }
        return $quoted;
    }

    public function payAttention($fn)
    {
        try {
            return call_user_func($fn);
        } catch(DBALException $e) {
            return new Response(
                ['error' => $e->getMessage()],
                $this->contentType,
                StatusCode::BAD_REQUEST);
        } catch(PDOException $e){
            return new Response(
                ['error' => $e->getMessage()],
                $this->contentType,
                StatusCode::BAD_REQUEST);
        } catch(\Exception $e){
            return new Response(
                ['error' => $e->getMessage()],
                $this->contentType,
                StatusCode::SERVER_ERROR
            );
        }
    }


    public function excludeColumns($items, $columns, $isMultidimensional = false)
    {
        if(empty($items)) {
            return [];
        }

        if($isMultidimensional) {
            return array_map(function ($item) use (&$columns) {
                return array_diff_key($item, array_flip($columns));
            }, $items);
        }
        return array_diff_key($items, array_flip($columns));
    }


    /**
     * Make type fixes (integer, float, etc.) to an item or collection of items according to the $fixes variable.
     * @param $items
     * @param array $fixes
     * @param bool|true $isMultidimencional
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function fixTypes($items, $fixes = [], $isMultidimencional = false)
    {
        if(empty($items) || empty($fixes)){
            return $items;
        }

        if($isMultidimencional){
            return array_map(function($item) use(&$fixes){

                foreach($fixes as $typeName => $fields){
                    if(is_array($fields)) {
                        foreach($fields as $fieldName){
                            $item[$fieldName] = Type::getType($typeName)
                                ->convertToPHPValue($item[$fieldName], $this->dbConn->getDatabasePlatform());
                        }
                    }
                }
                return $item;

            }, $items);
        }

        foreach($fixes as $typeName => $fields){
            if(is_array($fields)) {
                foreach($fields as $fieldName){
                    $items[$fieldName] = Type::getType($typeName)
                        ->convertToPHPValue($items[$fieldName], $this->dbConn->getDatabasePlatform());
                }
            }
        }

        return $items;
    }
}