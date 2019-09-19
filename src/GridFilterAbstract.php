<?php declare(strict_types=1);

namespace Hanaboso\DataGrid;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Hanaboso\DataGrid\Exception\GridException;
use Hanaboso\DataGrid\Result\ResultData;
use LogicException;

/**
 * Class GridFilterAbstract
 *
 * @package Hanaboso\DataGrid
 */
abstract class GridFilterAbstract
{

    public const EQ       = 'EQ';
    public const NEQ      = 'NEQ';
    public const GT       = 'GT';
    public const LT       = 'LT';
    public const GTE      = 'GTE';
    public const LTE      = 'LTE';
    public const LIKE     = 'LIKE';
    public const STARTS   = 'STARTS';
    public const ENDS     = 'ENDS';
    public const NEMPTY   = 'NEMPTY';
    public const EMPTY    = 'EMPTY';
    public const BETWEEN  = 'BETWEEN';
    public const NBETWEEN = 'NBETWEEN';

    public const ASCENDING  = 'ASC';
    public const DESCENDING = 'DESC';

    public const COLUMN    = 'column';
    public const OPERATOR  = 'operator';
    public const VALUE     = 'value';
    public const DIRECTION = 'direction';
    public const SEARCH    = 'search';

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var string
     */
    protected $entity;

    /**
     * @var QueryBuilder
     */
    protected $searchQuery;

    /**
     * @var QueryBuilder|NULL
     */
    protected $countQuery = NULL;

    /**
     * @var array
     */
    protected $filters;

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
     * @var array
     */
    protected $filterColsCallbacks = [];

    /**
     * @var bool
     */
    protected $fetchJoin = TRUE;

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
     * @param GridRequestDtoInterface $gridRequestDto
     * @param array                   $dateFields
     *
     * @return array
     * @throws GridException
     */
    public function getData(GridRequestDtoInterface $gridRequestDto, array $dateFields = []): array
    {
        $this->prepareSearchQuery();
        $this->processSortations($gridRequestDto);
        $this->processConditions($gridRequestDto, $this->searchQuery);

        if ($this->countQuery) {
            $this->processConditions($gridRequestDto, $this->countQuery);
        } else {
            $this->countQuery = clone $this->searchQuery;
        }

        $this->processPagination($gridRequestDto);

        $data = new ResultData($this->searchQuery->getQuery());
        $gridRequestDto->setTotal($this->count());

        return $data->toArray(AbstractQuery::HYDRATE_OBJECT, $dateFields);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        /** @var QueryBuilder $query */
        $query = $this->countQuery;

        return (int) (new Paginator($query, $this->fetchJoin))
            ->setUseOutputWalkers(FALSE)
            ->count();
    }

    /**
     * @return EntityRepository|ObjectRepository
     */
    public function getRepository(): ObjectRepository
    {
        return $this->em->getRepository($this->entity);
    }

    /**
     * @param GridRequestDtoInterface $dto
     *
     * @throws GridException
     */
    private function processSortations(GridRequestDtoInterface $dto): void
    {
        $sortations = $dto->getOrderBy();

        if ($sortations) {
            foreach ($sortations as $sortation) {
                $column    = $sortation[self::COLUMN];
                $direction = $sortation[self::DIRECTION];
                if (!isset($this->orderCols[$column])) {
                    throw new GridException(
                        sprintf(
                            "Column '%s' cannot be used for sorting! Have you forgotten add it to '%s::orderCols'?",
                            $column,
                            static::class
                        ),
                        GridException::SORT_COLS_ERROR
                    );
                }

                $this->searchQuery->addOrderBy($this->orderCols[$column], $direction);
            }
        }
    }

    /**
     * @param GridRequestDtoInterface $dto
     * @param QueryBuilder            $builder
     *
     * @throws GridException
     */
    private function processConditions(GridRequestDtoInterface $dto, QueryBuilder $builder): void
    {
        $conditions                  = $dto->getFilter();
        $advancedConditionExpression = $builder->expr()->andX();

        $exp = FALSE;
        foreach ($conditions as $andCondition) {
            $hasExpression = FALSE;
            $expression    = $builder->expr()->orX();

            foreach ($andCondition as $orCondition) {
                if (!array_key_exists(self::COLUMN, $orCondition) ||
                    !array_key_exists(self::OPERATOR, $orCondition) ||
                    !array_key_exists(self::VALUE, $orCondition) &&
                    !in_array($orCondition[self::OPERATOR], [self::NEMPTY, self::EMPTY], TRUE)) {
                    throw new LogicException(sprintf(
                        "Filter must have '%s', '%s' and '%s' field!",
                        self::COLUMN,
                        self::OPERATOR,
                        self::VALUE
                    ));
                }

                if (!array_key_exists(self::VALUE, $orCondition)) {
                    $orCondition[self::VALUE] = '';
                }

                $column = $orCondition[self::COLUMN];

                $this->checkFilterColumn($column);
                $hasExpression = TRUE;

                if (isset($this->filterColsCallbacks[$column])) {
                    $this->filterColsCallbacks[$column](
                        $this->searchQuery,
                        $orCondition[self::VALUE],
                        $this->filterCols[$column],
                        $expression,
                        $orCondition[self::OPERATOR]
                    );
                    continue;
                }

                $expression->add(self::getCondition(
                    $builder,
                    $this->filterCols[$column],
                    $orCondition[self::VALUE],
                    $orCondition[self::OPERATOR]
                ));
            }

            if ($hasExpression) {
                $advancedConditionExpression = $advancedConditionExpression->add($expression);
                $exp                         = TRUE;
            }
        }

        if ($exp) {
            $builder->andWhere($advancedConditionExpression);
        }

        $search = $dto->getSearch();
        if ($search) {
            $searchExpression = $builder->expr()->orX();

            if (!$this->searchableCols) {
                throw new GridException(
                    sprintf(
                        "Column cannot be used for searching! Have you forgotten add it to '%s::searchableCols'?",
                        static::class
                    ),
                    GridException::SEARCH_COLS_ERROR
                );
            }

            foreach ($this->searchableCols as $column) {
                if (!array_key_exists($column, $this->filterCols)) {
                    throw new GridException(
                        sprintf(
                            "Column '%s' cannot be used for searching! Have you forgotten add it to '%s::filterCols'?",
                            $column,
                            static::class
                        ),
                        GridException::SEARCH_COLS_ERROR
                    );
                }
                $column = $this->filterCols[$column];

                if (isset($this->filterColsCallbacks[$column])) {
                    $expression = $builder->expr();

                    $this->filterColsCallbacks[$column](
                        $this->searchQuery,
                        $search,
                        $this->filterCols[$column],
                        $expression,
                        NULL
                    );

                    $searchExpression->add($expression);
                    continue;
                }

                $searchExpression->add(self::getCondition($builder, $column, $search, self::LIKE));
            }

            $builder->andWhere($searchExpression);
        }
    }

    /**
     * @param GridRequestDtoInterface $dto
     */
    private function processPagination(GridRequestDtoInterface $dto): void
    {
        $page         = $dto->getPage();
        $itemsPerPage = $dto->getItemsPerPage();

        $this->searchQuery
            ->setFirstResult(--$page * $itemsPerPage)
            ->setMaxResults($itemsPerPage);
    }

    /**
     * @param string $column
     *
     * @throws GridException
     */
    private function checkFilterColumn(string $column): void
    {
        if (!isset($this->filterCols[$column])) {
            throw new GridException(
                sprintf(
                    "Column '%s' cannot be used for filtering! Have you forgotten add it to '%s::filterCols'?",
                    $column,
                    static::class
                ),
                GridException::FILTER_COLS_ERROR
            );
        }
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
     * @param QueryBuilder $builder
     * @param string       $name
     * @param mixed        $value
     * @param string|NULL  $operator
     *
     * @return mixed
     */
    public static function getCondition(QueryBuilder $builder, string $name, $value, ?string $operator = NULL)
    {
        switch ($operator) {
            case self::EQ:
                if (is_array($value)) {
                    return count($value) > 1
                        ? $builder->expr()->in($name, self::getValue($value))
                        : $builder->expr()->eq($name, self::getValue($value[0]));
                } else {
                    return $builder->expr()->eq($name, self::getValue($value[0]));
                }
            case self::NEQ:
                return is_array($value) ?
                    $builder->expr()->notIn($name, self::getValue($value)) :
                    $builder->expr()->neq($name, self::getValue($value[0]));
            case self::GTE:
                return $builder->expr()->gte($name, self::getValue($value[0]));
            case self::GT:
                return $builder->expr()->gt($name, self::getValue($value[0]));
            case self::LTE:
                return $builder->expr()->lte($name, self::getValue($value[0]));
            case self::LT:
                return $builder->expr()->lt($name, self::getValue($value[0]));
            case self::NEMPTY:
                return $builder->expr()->isNotNull($name);
            case self::EMPTY:
                return $builder->expr()->isNull($name);
            case self::LIKE:
                return $builder->expr()->like($name, sprintf("'%%%s%%'", $value[0]));
            case self::STARTS:
                return $builder->expr()->like($name, sprintf("'%s%%'", $value[0]));
            case self::ENDS:
                return $builder->expr()->like($name, sprintf("'%%%s'", $value[0]));
            case self::BETWEEN:
                if (is_array($value) && count($value) >= 2) {
                    return $builder->expr()->between($name, self::getValue($value[0]), self::getValue($value[1]));
                }

                return $builder->expr()->eq($name, self::getValue($value));
            case self::NBETWEEN:
                if (is_array($value) && count($value) >= 2) {
                    return $builder->expr()
                        ->orX(
                            $builder->expr()->lte($name, self::getValue($value[0])),
                            $builder->expr()->gte($name, self::getValue($value[1]))
                        );
                }

                return $builder->expr()->neq($name, self::getValue($value[0]));
            default:
                return $builder->expr()->eq($name, self::getValue($value[0]));
        }
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function getValue($value)
    {
        if (is_numeric($value)) {
            return sprintf('%s', $value);
        } elseif (is_bool($value)) {
            return sprintf('%s', $value ? 'true' : 'false');
        } elseif (is_string($value)) {
            return sprintf('\'%s\'', $value);
        } elseif (is_null($value)) {
            return '\'\'';
        }

        return $value;
    }

}
