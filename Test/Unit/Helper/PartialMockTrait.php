<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Helper;

use PHPUnit\Framework\MockObject\MockObject;

trait PartialMockTrait
{
    /**
     * Partial mock that may stub magic methods, which `onlyMethods()` rejects.
     *
     * @param string $className
     * @param string[] $methods
     * @return MockObject
     */
    protected function createPartialMockWithReflection(string $className, array $methods): MockObject
    {
        $mockBuilder = $this->getMockBuilder($className)->disableOriginalConstructor();
        (new \ReflectionProperty($mockBuilder, 'methods'))->setValue($mockBuilder, $methods);

        return $mockBuilder->getMock();
    }
}
