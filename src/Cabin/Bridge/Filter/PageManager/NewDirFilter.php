<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Filter\PageManager;

use \Airship\Engine\Security\Filter\{
    InputFilterContainer,
    StringFilter
};

/**
 * Class NewDirFilter
 * @package Airship\Cabin\Bridge\Filter\Account
 */
class NewDirFilter extends InputFilterContainer
{
    /**
     * NewDirFilter constructor.
     */
    public function __construct()
    {
        $this->addFilter(
                'url',
                (new StringFilter())
                    ->addCallback([StringFilter::class, 'nonEmpty'])
            );
    }
}
