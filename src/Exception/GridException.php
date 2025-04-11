<?php declare(strict_types=1);

namespace Hanaboso\DataGrid\Exception;

use Exception;

/**
 * Class GridException
 *
 * @package Hanaboso\DataGrid\Exception
 */
final class GridException extends Exception
{

    public const int FETCH_ERROR              = 1;
    public const int FETCH_ONE_ERROR          = 2;
    public const int MODIFY_RESULT_DATA_ERROR = 3;
    public const int FILTER_COLS_ERROR        = 4;
    public const int GET_TOTAL_COUNT_ERROR    = 5;
    public const int SEARCH_COLS_ERROR        = 6;
    public const int SEARCH_QUERY_NOT_FOUND   = 7;
    public const int SORT_COLS_ERROR          = 8;

}
