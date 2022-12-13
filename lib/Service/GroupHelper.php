<?php

namespace OCA\NextMagentaCloudProvisioning\Service;

use OCP\IConfig;

class GroupHelper
{

    /**
     * @var \OCP\IConfig
     */
    private IConfig $config;

    /**
     * @param \OCP\IConfig $config
     */
    public function __construct(IConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @var array $groupMapping
     */
    private array $groupMapping = [
        /*'NONE' => [
            'name' => 'NONE',
            'space_limit' => 0,
            'old_group' => 'NONE',
        ],
        'FREE3' => [
            'name' => 'FREE3',
            'space_limit' => '3 GB',
            'old_group' => 'FREE3',
        ],
        'FREE10' => [
            'name' => 'FREE10',
            'space_limit' => '10 GB',
            'old_group' => 'FREE10',
        ],*/
        'S15' => [
            'name' => 'S15',
            'space_limit' => '15 GB',
            'old_group' => 'S15',
            'old_quota' => '15',
            'search_range' => '1',
            'ready_only' => true,
        ],
        'S25' => [
            'name' => 'S25',
            'space_limit' => '25 GB',
            'old_group' => 'S25',
            'old_quota' => '25',
            'search_range' => '1',
            'read_only' => true,
        ],
        'M100' => [
            'name' => 'M100',
            'space_limit' => '100 GB',
            'old_group' => 'M100',
            'old_quota' => '100',
            'search_range' => '1',
        ],
        'L500' => [
            'name' => 'L500',
            'space_limit' => '500 GB',
            'old_group' => 'L500',
            'old_quota' => '500',
            'search_range' => '1',
        ],
        'XL1' => [
            'name' => 'XL1',
            'space_limit' => '1 TB',
            'old_group' => 'XL1',
            'old_quota' => '1024',
            'search_range' => '1',
        ],
        'XXL5' => [
            'name' => 'XXL5',
            'space_limit' => '5 TB',
            'old_group' => 'XXL5',
            'old_quota' => '5120',
            'search_range' => '1',
        ],
    ];

    /**
     * @return array
     */
    public function getGroupMapping(): array
    {
        return $this->groupMapping;
    }


}