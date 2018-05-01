<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Kaliop\eZMigrationBundle\API\Value\MigrationStep;
use Kaliop\eZMigrationBundle\API\ReferenceResolverInterface;
use Kaliop\eZMigrationBundle\API\EmbeddedReferenceResolverInterface;
use Kaliop\eZMigrationBundle\Core\ReferenceResolver\PrefixBasedResolverInterface;
use Psr\Http\Message\ResponseInterface;

class HTTPExecutor extends AbstractExecutor
{
    use IgnorableStepExecutorTrait;

    protected $supportedStepTypes = array('http');
    protected $supportedActions = array('call');

    /** @var ReferenceResolverInterface $referenceResolver */
    protected $referenceResolver;

    protected $container;

    /**
     * @param ContainerInterface $container
     * @param PrefixBasedResolverInterface $referenceResolver has to implement EmbeddedReferenceResolverInterface as well!
     */
    public function __construct(ContainerInterface $container, PrefixBasedResolverInterface $referenceResolver)
    {
        $this->referenceResolver = $referenceResolver;
        $this->container = $container;
    }

    /**
     * @param MigrationStep $step
     * @return mixed
     * @throws \Exception
     */
    public function execute(MigrationStep $step)
    {
        parent::execute($step);

        if (!isset($step->dsl['mode'])) {
            throw new \Exception("Invalid step definition: missing 'mode'");
        }

        $action = $step->dsl['mode'];

        if (!in_array($action, $this->supportedActions)) {
            throw new \Exception("Invalid step definition: value '$action' is not allowed for 'mode'");
        }

        $this->skipStepIfNeeded($step);

        return $this->$action($step->dsl, $step->context);
    }

    /**
     * @param array $dsl
     * @param array $context
     * @return true
     * @throws \Exception
     */
    protected function call($dsl, $context)
    {
        if (!isset($dsl['uri'])) {
            throw new \Exception("Can not execute http call without 'uri' in the step definition");
        }

        $method = isset($dsl['method']) ? $dsl['method'] : 'GET';

        $uri = $this->resolveReferencesInText($dsl['uri']);

        $headers = isset($dsl['headers']) ? $this->resolveReferencesInTextRecursively($dsl['headers']) : array();

        $body = isset($dsl['body']) ? $this->resolveReferencesInText($dsl['body']) : null;

        if (isset($dsl['client'])) {
            $client = $this->container->get('httplug.client.'.$dsl['client']);
        } else {
            $client = $this->container->get('httplug.client');
        }

        $request = $this->container->get('httplug.message_factory')->createRequest($method, $uri, $headers, $body);

        $response = $client->sendRequest($request);

        $this->setReferences($response, $dsl);

        return $response;
    }

    /**
     * @param ResponseInterface $response
     * @param array $dsl
     * @return bool
     * @todo use jmespath syntax to allow setting refs to response headers
     */
    protected function setReferences(ResponseInterface $response, $dsl)
    {
        if (!array_key_exists('references', $dsl)) {
            return false;
        }

        foreach ($dsl['references'] as $reference) {
            switch ($reference['attribute']) {
                case 'status_code':
                    $value = $response->getStatusCode();
                    break;
                case 'reason_phrase':
                    $value = $response->getReasonPhrase();
                    break;
                case 'protocol_version':
                    $value = $response->getProtocolVersion();
                    break;
                case 'body':
                    $value = $response->getBody()->__toString();
                    break;
                case 'body_size':
                    $value = $response->getBody()->getSize();
                    break;
                default:
                    throw new \InvalidArgumentException('HTTP executor does not support setting references for attribute ' . $reference['attribute']);
            }

            $overwrite = false;
            if (isset($reference['overwrite'])) {
                $overwrite = $reference['overwrite'];
            }
            $this->referenceResolver->addReference($reference['identifier'], $value, $overwrite);
        }

        return true;
    }

    /**
     * @deprecated should be moved into the reference resolver classes
     */
    protected function resolveReferencesRecursively($match)
    {
        if (is_array($match)) {
            foreach ($match as $condition => $values) {
                $match[$condition] = $this->resolveReferencesRecursively($values);
            }
            return $match;
        } else {
            return $this->referenceResolver->resolveReference($match);
        }
    }

    /**
     * Replaces any references inside a string
     *
     * @param string
     * @return string
     * @throws \Exception
     */
    protected function resolveReferencesInText($text)
    {
        if (!$this->referenceResolver instanceof EmbeddedReferenceResolverInterface) {
            throw new \Exception("Reference resolver passed to HTTPExecutor should implement EmbeddedReferenceResolverInterface");
        }

        return $this->referenceResolver->ResolveEmbeddedReferences($text);
    }

    protected function resolveReferencesInTextRecursively($textOrArray)
    {
        if (is_array($textOrArray)) {
            foreach ($textOrArray as $condition => $values) {
                $textOrArray[$condition] = $this->resolveReferencesInTextRecursively($values);
            }
            return $textOrArray;
        } else {
            return $this->resolveReferencesInText($textOrArray);
        }
    }
}
