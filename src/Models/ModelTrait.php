<?php

namespace GP247\Core\Models;

/**
 * Trait Model.
 */
trait ModelTrait
{
    protected $gp247_limit = 0; // 0 is all
    protected $gp247_paginate = 0; // 0: dont paginate,
    protected $gp247_sort = [];
    protected $gp247_moreQuery = []; // more query
    protected $gp247_random = 0; // 0: no random, 1: random
    protected $gp247_keyword = ''; // search search product
 

    
    /**
     * Set value limit
     * @param   [int]  $limit
     */
    public function setLimit($limit)
    {
        $this->gp247_limit = (int)$limit;
        return $this;
    }

    /**
     * Set value sort
     * @param   [array]  $sort ['field', 'asc|desc']
     * Support format ['field' => 'asc|desc']
     */
    public function setSort(array $sort)
    {
        if (is_array($sort)) {
            if (count($sort) == 1) {
                foreach ($sort as $kS => $vS) {
                    $sort = [$kS, $vS];
                }
            }
            $this->gp247_sort[] = $sort;
        }
        return $this;
    }

    /**
     * [setMoreQuery description]
     *
     * @param  array  $moreQuery  [$moreQuery description]
     * EX: 
     * -- setMoreQuery(['where' => ['columnA','>',12]]) 
     * -- setMoreQuery(['orderBy' => ['columnA','asc']])
     * 
     * @return  [type]              [return description]
     */

    public function setMoreQuery(array $moreQuery)
    {
        if (is_array($moreQuery)) {
            $this->gp247_moreQuery[] = $moreQuery;
        }
        return $this;
    }

    /**
     * process more query
     *
     * @param   [type]  $query  [$query description]
     *
     * @return  [type]          [return description]
     */
    protected function processMoreQuery($query) {
        if (count($this->gp247_moreQuery)) {
            foreach ($this->gp247_moreQuery as $objQuery) {
                if (is_array($objQuery) && count($objQuery) == 1) {
                    foreach ($objQuery as $queryType => $obj) {
                        if (!is_numeric($queryType) && is_array($obj)) {
                            $query = $query->{$queryType}(...$obj);
                        }
                    }
                }
            }
        }
        return $query;
    }

    /**
     * Enable paginate mode
     *  0 - no paginate
     */
    public function setPaginate(int $value = 1)
    {
        $this->gp247_paginate = $value;
        return $this;
    }

    /**
     * Set random mode
     */
    public function setRandom(int $value = 1)
    {
        $this->gp247_random = $value;
        return $this;
    }
    
    /**
     * Set keyword search
     * @param   [string]  $keyword
     */
    public function setKeyword(string $keyword)
    {
        if (trim($keyword)) {
            $this->gp247_keyword = trim($keyword);
        }
        return $this;
    }


    /**
    * Get Sql
    */
    public function getSql()
    {
        $query = $this->buildQuery();
        if (!$this->gp247_paginate) {
            if ($this->gp247_limit) {
                $query = $query->limit($this->gp247_limit);
            }
        }
        return $query = $query->toSql();
    }

    /**
    * Get data
    * @param   [array]  $action
    */
    public function getData(array $action = [])
    {
        $query = $this->buildQuery();
        if (!empty($action['query'])) {
            return $query;
        }
        if ($this->gp247_paginate) {
            $data =  $query->paginate((!$this->gp247_limit) ? 20 : $this->gp247_limit);
        } else {
            if ($this->gp247_limit) {
                $query = $query->limit($this->gp247_limit);
            }
            $data = $query->get();
                
            if (!empty($action['keyBy'])) {
                $data = $data->keyBy($action['keyBy']);
            }
            if (!empty($action['groupBy'])) {
                $data = $data->groupBy($action['groupBy']);
            }
        }
        return $data;
    }

    /**
     * Get full data
     *
     * @return  [type]  [return description]
     */
    public function getFull()
    {
        if (method_exists($this, 'getDetail')) {
            return $this->getDetail($this->id);
        } else {
            return $this;
        }
    }
    
    /**
     * Get all custom fields
     *
     * @return void
     */
    public function getCustomFields()
    {
        $type = $this->getTable();
        $data =  (new \GP247\Core\Models\AdminCustomFieldDetail)
            ->join(GP247_DB_PREFIX.'admin_custom_field', GP247_DB_PREFIX.'admin_custom_field.id', GP247_DB_PREFIX.'admin_custom_field_detail.custom_field_id')
            ->select('code', 'name', 'text')
            ->where(GP247_DB_PREFIX.'admin_custom_field_detail.rel_id', $this->id)
            ->where(GP247_DB_PREFIX.'admin_custom_field.type', $type)
            ->where(GP247_DB_PREFIX.'admin_custom_field.status', '1')
            ->get()
            ->keyBy('code');
        return $data;
    }

    /**
     * Get custom field
     *
     * @return void
     */
    public function getCustomField($code = null)
    {
        $type = $this->getTable();
        $data =  (new \GP247\Core\Models\AdminCustomFieldDetail)
            ->join(GP247_DB_PREFIX.'admin_custom_field', GP247_DB_PREFIX.'admin_custom_field.id', GP247_DB_PREFIX.'admin_custom_field_detail.custom_field_id')
            ->select('code', 'name', 'text')
            ->where(GP247_DB_PREFIX.'admin_custom_field_detail.rel_id', $this->id)
            ->where(GP247_DB_PREFIX.'admin_custom_field.type', $type)
            ->where(GP247_DB_PREFIX.'admin_custom_field.status', '1');
        if ($code) {
            $data = $data->where(GP247_DB_PREFIX.'admin_custom_field.code', $code);
        }
        $data = $data->first();
        return $data;
    }

    /*
    Get custom fields via attribute
    $item->custom_fields
     */
    public function getCustomFieldsAttribute()
    {
        return $this->getCustomFields();
    }
}
