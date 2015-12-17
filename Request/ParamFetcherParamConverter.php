<?php

namespace FOS\RestBundle\Request;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Request\ParamConverter\ParamConverterInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class ParamFetcherParamConverter implements ParamConverterInterface
{
    /**
     * @inheritDoc
     */
    public function apply(Request $request, ParamConverter $configuration)
    {
        // TODO: Implement apply() method.
    }

    /**
     * @inheritDoc
     */
    public function supports(ParamConverter $configuration)
    {
        $class_ = $configuration->getClass();
        if (null === $class_) {
            return false;
        }

        $reflClass = new \ReflectionClass($class_);
        return $reflClass->implementsInterface(ParamFetcherInterface::class);
    }

    protected function createParamFetcher(Request $request)
    {
        $paramFetcher = new ParamFetcher(
            $request,
            $this->container->get('fos_rest.violation_formatter'),
            $this->container->get('validator', ContainerInterface::NULL_ON_INVALID_REFERENCE)
        );

        $paramFetcher->setContainer($this->container);
        return $paramFetcher;
    }
}