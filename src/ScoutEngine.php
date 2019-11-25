<?php

namespace Basemkhirat\Elasticsearch;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Elasticsearch\Client as Elastic;
use Illuminate\Database\Eloquent\Collection;
use Mockery\Exception;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Log;

class ScoutEngine extends Engine
{

    /**
     * Index where the models will be saved.
     * @var string
     */
    protected $index;

    /**
     * ScoutEngine constructor.
     * @param Elastic $elastic
     * @param $index
     */
    public function __construct(Elastic $elastic, $index)
    {
        $this->elastic = $elastic;
        $this->index = $index;
    }

    /**
     * Update the given model in the index.
     * @param  Collection  $models
     * @return void
     */
    public function update($models)
    {
        $params['body'] = [];

        $models->each(function($model) use (&$params)
        {

            $params['body'][] = [

                'update' => [
                    '_id' => $model->getKey(),
                    '_index' => $model->indexAs(),
                    '_type' => $model->searchableAs(),
                ]
            ];

            $convert = $model->toSearchableArray();

            //es不支持逗号，转为冒号
          /*  if($model->searchableAs()=='assets'||$model->searchableAs()=='res_group_assets'){
                if (isset($convert['sort_str'])){
                    if(!in_array($convert['sort_str'], ['null','NaN',""])){
                        $convert['sort_str'] = $this->convertCategoryId($convert['sort_str']);
                        $convert['single_sort_str'] = $this->getCategoryId($convert['sort_str']);
                    }
                }
                if (isset($convert['view_sort_str'])){
                    if(!in_array($convert['view_sort_str'], ['null','NaN',""])){
                        $convert['view_sort_str'] = $this->convertCategoryId($convert['view_sort_str']);
                    }
                }
            }*/
            $params['body'][] = [
                'doc' => $convert,
                'doc_as_upsert' => true
            ];
        });

        $params['refresh']=true;
        $res = $this->elastic->bulk($params);

        if(isset($res['errors'])&&$res['errors']){
            #Log::Info(json_encode($res["items"]));
        }

    }

    //将提交的分类ID转换为可精确匹配的分类格式
    public function convertCategoryId($resourceCategoryId)
    {
        $resourceCategoryId = str_replace([':','；'],';',$resourceCategoryId);
        $newCategory = str_replace(',',':',$resourceCategoryId);
        return $newCategory;
    }


    public function getCategoryId($categoryIds){
        $cidArr = explode(';', $categoryIds);
        $singleStr = "";
        foreach ($cidArr as $k => $v) {
            if(count(array_filter(explode(':',$v)))==1){
                $singleStr.= $v.';';
            }
        }
        return rtrim($singleStr,';');
    }

    /**
     * Remove the given model from the index.
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $params['body'] = [];

        $models->each(function($model) use (&$params)
        {
            $params['body'][] = [
                'delete' => [
                    '_id' => $model->getKey(),
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                ]
            ];
        });
        $params['refresh']=true;///

        $this->elastic->bulk($params);
    }

    /**
     * Perform the given search on the engine.
     * @param  Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'size' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     * @param  Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $result = $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'from' => (($page * $perPage) - $perPage),
            'size' => $perPage,
        ]);

        $result['nbPages'] = $result['hits']['total']/$perPage;

        return $result;
    }

    /**
     * Perform the given search on the engine.
     * @param  Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $params = [
            'index' => $this->index,
            'type' => $builder->model->searchableAs(),
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [['query_string' => [ 'query' => $builder->query]]]
                    ]
                ]
            ]
        ];

        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }

        if (isset($options['size'])) {
            $params['body']['size'] = $options['size'];
        }

        if (isset($options['numericFilters']) && count($options['numericFilters'])) {
            $params['body']['query']['bool']['must'] = array_merge($params['body']['query']['bool']['must'],
                $options['numericFilters']);
        }

        return $this->elastic->search($params);
    }

    /**
     * Get the filter array for the query.
     * @param  Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            return ['match_phrase' => [$key => $value]];
        })->values()->all();
    }

    /**
     * Map the given results to instances of the given model.
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return Collection
     */
    public function map($results, $model)
    {
        if (count($results['hits']['total']) === 0) {
            return Collection::make();
        }

        $keys = collect($results['hits']['hits'])
            ->pluck('_id')->values()->all();

        $models = $model->whereIn(
            $model->getKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return collect($results['hits']['hits'])->map(function ($hit) use ($model, $models) {
            return $models[$hit['_id']];
        });
    }

    public function mapIds($results){}

    /**
     * Get the total count from a raw result returned by the engine.
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total'];
    }
}
