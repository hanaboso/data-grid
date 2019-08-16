<?php declare(strict_types=1);

namespace Hanaboso\DataGrid;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Hanaboso\DataGrid\Exception\GridException;
use Hanaboso\DataGrid\Query\FilterCallbackDto;
use Hanaboso\DataGrid\Query\QueryModifier;
use Hanaboso\DataGrid\Query\QueryObject;
use Hanaboso\DataGrid\Result\ResultData;

/**
 * Class GridFilterAbstract
 *
 * @package Hanaboso\DataGrid
 */
abstract class GridFilterAbstract
{

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var string
     */
    protected $entity;

    /**
     * @var string
     */
    protected $search;

    /**
     * @var array
     */
    protected $filters;

    /**
     * @var array
     */
    protected $advancedFilters;

    /**
     * @var string
     */
    protected $order;

    /**
     * @var array
     */
    protected $filterColsCallbacks = [];

    /**
     * @var array
     */
    protected $filterCols = [];

    /**
     * @var array
     */
    protected $orderCols = [];

    /**
     * @var array
     */
    protected $searchableCols = [];

    /**
     * @var QueryBuilder|NULL
     */
    protected $searchQuery;

    /**
     * @var array
     */
    protected $searchQueryParams;

    /**
     * @var QueryBuilder|NULL
     */
    protected $countQuery = NULL;

    /**
     * @var bool
     */
    protected $fetchJoin = TRUE;

    /**
     * @var bool
     */
    protected $useOutputWalkers = FALSE;

    /**
     * @var int
     */
    private $whispererLimit = 50;

    /**
     * GridFilterAbstract constructor.
     *
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em)
    {
        $this->em = $em;
        $this->setEntity();
        $this->configFilterColsCallbacks();
        $this->configCustomCountQuery();
        $this->prepareSearchQuery();
    }

    /**
     *
     */
    abstract protected function prepareSearchQuery(): void;

    /**
     *
     */
    abstract protected function setEntity(): void;

    /**
     * @param array $filter
     *
     * @return array
     * @throws GridException
     */
    public function getWhispererData(array $filter = []): array
    {
        $this->filters = QueryModifier::getFilters($filter, $this->filterCols, $this->filterColsCallbacks);
        $this->search  = QueryModifier::getSearch($filter);

        $arr = [];

        foreach ($this->searchableCols as $name => $col) {
            $object  = $this->getFilteredQuery([$col => $name])->select(sprintf('%s AS %s', $name, $col));
            $results = $this->getResultData($object);
            if (!is_array($results)) {
                $results = $results->toArray();
            }

            $i = 0;
            foreach ($results as $result) {
                if ($i > $this->whispererLimit) {
                    break;
                }
                $arr[] = $result[$col];
                $i++;
            }
        }

        return array_unique($arr);
    }

    /**
     * @param GridRequestDtoInterface $gridRequestDto
     *
     * @return ResultData
     * @throws GridException
     */
    public function getData(GridRequestDtoInterface $gridRequestDto): ResultData
    {
        if (!empty($this->searchQueryParams)) {
            $this->prepareSearchQuery();
        }

        $object = $this->getQuery(
            $gridRequestDto->getFilter(),
            $gridRequestDto->getAdvancedFilter(),
            $gridRequestDto->getOrderBy()
        );
        /** @var ResultData $data */
        $data = $this->getResultData($object);

        if (!empty($gridRequestDto->getOrderBy())) {
            $data->applySorting($this->order);
        }

        $data->applyPagination(intval($gridRequestDto->getPage()), $gridRequestDto->getLimit());

        $gridRequestDto->setTotal($data->getTotalCount());

        return $data;
    }

    /**
     * @return array
     */
    public function getColumns(): array
    {
        return [
            'filter' => $this->filterCols,
            'search' => $this->searchableCols,
            'order'  => $this->orderCols,
        ];
    }

    /**
     * @return EntityRepository|ObjectRepository
     */
    public function getRepository()
    {
        return $this->em->getRepository($this->entity);
    }

    /**
     * @param array $params
     */
    public function setSearchQueryParams(array $params): void
    {
        $this->searchQueryParams = $params;
    }

    /**
     * -------------------------------------------- HELPERS -----------------------------------------------
     */

    /**
     * In child can configure GridFilterAbstract::filterColsCallbacks
     * example child content
     * $this->filterColsCallbacks[ESomeEnumCols::CREATED_AT_FROM] = [$object,'applyCreatedAtFrom']
     *
     * function applySomeFilter(QueryBuilder $qb,$filterVal,$colName){}
     */
    protected function configFilterColsCallbacks(): void
    {
    }

    /**
     * In child can configure GridFilterAbstract::configCustomCountQuery
     * example child content
     * $this->countQuery = $this->getRepository()->createQueryBuilder('c')->select('count(c.id)')
     */
    protected function configCustomCountQuery(): void
    {
    }

    /**
     * @param array $filter
     * @param array $advancedFilter
     * @param array $order
     *
     * @return QueryObject
     * @throws GridException
     */
    private function getQuery(array $filter = [], array $advancedFilter = [], array $order = []): QueryObject
    {
        $this->search          = QueryModifier::getSearch($filter);
        $this->filters         = QueryModifier::getFilters($filter, $this->filterCols, $this->filterColsCallbacks);
        $this->advancedFilters = QueryModifier::getAdvancedFilters(
            $advancedFilter,
            $this->filterCols,
            $this->filterColsCallbacks
        );

        if (!empty($order)) {
            $this->order = QueryModifier::getOrderString($order, $this->orderCols);
        }

        return $this->getFilteredQuery();
    }

    /**
     * @param array $cols
     *
     * @return QueryObject
     * @throws GridException
     */
    private function getFilteredQuery(array $cols = []): QueryObject
    {
        if (empty($cols)) {
            $cols = $this->getSearchCols();
        }

        foreach ($cols as $name => $col) {
            if (isset($this->filterColsCallbacks[$name])) {
                $cols[$name] = new FilterCallbackDto($this->filterColsCallbacks[$name], NULL, $col);
            }
        }

        return new QueryObject(
            $this->filters,
            $this->advancedFilters,
            $cols,
            $this->search,
            $this->getSearchQuery(),
            $this->countQuery,
            $this->fetchJoin,
            $this->useOutputWalkers
        );
    }

    /**
     * @return array
     * @throws GridException
     */
    private function getSearchCols(): array
    {
        $searchCols = [];
        foreach ($this->searchableCols as $col) {
            if (!isset($this->orderCols[$col])) {
                $class = self::class;
                throw new GridException(
                    sprintf(
                        'Key %s contained %s::typeCols is not defined in %s::orderCols. Add definition %s::orderCols[\'%s\'] = "some db field"',
                        $col, $class, $class, $class, $col
                    ),
                    GridException::SEARCH_COLS_ERROR
                );
            }
            $searchCols[$col] = $this->orderCols[$col];
        }

        return $searchCols;
    }

    /**
     * @return QueryBuilder
     * @throws GridException
     */
    private function getSearchQuery(): QueryBuilder
    {
        if (!$this->searchQuery) {
            throw new GridException(
                sprintf('QueryBuilder is missing. Add definition %s::searchQuery = "some db field"', self::class),
                GridException::SEARCH_QUERY_NOT_FOUND
            );
        }

        return $this->searchQuery;
    }

    /**
     * @param QueryObject $object
     *
     * @return ResultData|array
     * @throws GridException
     */
    private function getResultData(QueryObject $object)
    {
        /** @var EntityRepository $repository */
        $repository = $this->getRepository();

        return $object->fetch($repository, AbstractQuery::HYDRATE_OBJECT);
    }

}
