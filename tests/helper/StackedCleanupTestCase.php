<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\TestHelper;

use OCP\AppFramework\Http\Response;


use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * This test case adds a stack with lambda function calls
 * that allows registration of micro-cleanup steps in a stack.
 * 
 * On tearDown, the functions are called in reverse order to clean
 * all fragments produced up to the point where test was successful
 */
class StackedCleanupTestCase extends TestCase {

    /**
     * Cleanup functions should not contain any assertions,
     * but are allowed to throw exceptions which are handled as warnings
     */
    public function addCleanup(callable $func) {
        \array_push($this->cleanupStack, $func);
    }

    public function setUp() : void {
        parent::setUp();

        $this->cleanupStack = [];
    }

    public function tearDown() : void {
        while ( $func = \array_pop($this->cleanupStack) ) {
            try {
                $func();
            } catch(\Throwable $e) {
                // handle all cleanup exceptions as warings
                // so that stack is really completely emptied
                // so that as many cleanup steps as possible where executed.
                $this->addWarn($e->getMessage());
            }
        }

        parent::tearDown();        
    }

}