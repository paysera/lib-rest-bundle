<?php

namespace Paysera\Bundle\RestBundle;

use Exception;
use Paysera\Bundle\RestBundle\Cache\CacheStrategyInterface;
use Paysera\Bundle\RestBundle\Normalizer\NameAwareDenormalizerInterface;
use Paysera\Bundle\RestBundle\Resolver\AttributeResolverInterface;
use Paysera\Bundle\RestBundle\Security\SecurityStrategyInterface;
use Paysera\Bundle\RestBundle\Service\PropertyPathConverter\PathConverter;
use Paysera\Bundle\RestBundle\Service\RequestApiResolver;
use Paysera\Component\Serializer\Exception\InvalidDataException;
use Paysera\Component\Serializer\Normalizer\NormalizerInterface;
use Paysera\Component\Serializer\Validation\PropertiesAwareValidator;
use Paysera\Component\Serializer\Validation\PropertyPathConverterInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Paysera\Bundle\RestBundle\Entity\ErrorConfig;
use Paysera\Bundle\RestBundle\Exception\ConfigurationException;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use Paysera\Component\Serializer\Encoding\DecoderInterface;
use Paysera\Bundle\RestBundle\Service\FormatDetector;
use Paysera\Bundle\RestBundle\Entity\Error;
use Paysera\Component\Serializer\Encoding\EncoderInterface;
use Paysera\Bundle\RestBundle\Exception\ApiException;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ApiManager
{
    /**
     * @var EncoderInterface[]
     */
    private $encoders;

    /**
     * @var DecoderInterface[]
     */
    private $decoders;

    private $errorConfig;

    private $validator;

    private $errorNormalizer;

    private $requestApiResolver;

    private $logger;

    /**
     * @var PropertyPathConverterInterface|null
     */
    private $propertyPathConverter;

    private $formatDetector;

    public function __construct(
        FormatDetector $formatDetector,
        LoggerInterface $logger,
        ValidatorInterface $validator,
        NormalizerInterface $errorNormalizer,
        RequestApiResolver $requestApiResolver
    ) {
        $this->formatDetector = $formatDetector;
        $this->logger = $logger;
        $this->validator = $validator;
        $this->errorNormalizer = $errorNormalizer;
        $this->requestApiResolver = $requestApiResolver;
        $this->errorConfig = new ErrorConfig();

        $this->encoders = [];
        $this->decoders = [];
    }

    /**
     * Adds encoder
     *
     * @param EncoderInterface $encoder
     * @param string           $format
     */
    public function addEncoder(EncoderInterface $encoder, $format)
    {
        $this->encoders[$format] = $encoder;
    }

    /**
     * Adds decoder
     *
     * @param DecoderInterface $decoder
     * @param string           $format
     */
    public function addDecoder(DecoderInterface $decoder, $format)
    {
        $this->decoders[$format] = $decoder;
    }

    /**
     * Sets errorConfig
     *
     * @param ErrorConfig $errorConfig
     */
    public function setErrorConfig($errorConfig)
    {
        $this->errorConfig = $errorConfig;
    }

    /**
     * Creates response for exception if some API is answering to this request.
     * If not, null is returned
     *
     * @param Request    $request
     * @param Exception $exception
     *
     * @throws Exception|ApiException
     * @throws Exception
     * @return Response|null
     */
    public function getResponseForException(Request $request, Exception $exception)
    {
        try {
            $api = $this->requestApiResolver->getApiForRequest($request);
        } catch (RuntimeException $runtimeException) {
            return new Response('', 500);
        }

        if ($api !== null) {
            $error = $this->createErrorFromException($exception);
            $this->fillErrorDefaults($error, $api);
            try {
                $encoder = $this->getEncoderForApi($request, $api);
                $result = $encoder->encode($this->errorNormalizer->mapFromEntity($error));
                $headers = array('Content-Type' => $encoder->getContentType());
            } catch (ApiException $exception) {
                if ($exception->getErrorCode() === $exception::NOT_ACCEPTABLE) {
                    $result = $error->getMessage();
                    $headers = array('Content-Type' => 'text/plain');
                } else {
                    throw $exception;
                }
            }
            return new Response($result, $error->getStatusCode(), $headers);
        }

        return null;
    }

    /**
     * Returns request mapper for this request
     *
     * @param Request $request
     *
     * @return NameAwareDenormalizerInterface|null
     */
    public function getRequestMapper(Request $request)
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $api->getRequestMapper($request->attributes->get('_controller'));
        }

        return null;
    }

    /**
     * Returns request query mapper for this request
     *
     * @param Request $request
     *
     * @return NameAwareDenormalizerInterface|null
     */
    public function getRequestQueryMapper(Request $request)
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $api->getRequestQueryMapper($request->attributes->get('_controller'));
        }

        return null;
    }

    /**
     * Returns validation group for this request
     *
     * @param Request $request
     *
     * @return array
     */
    public function getValidationGroups(Request $request)
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $api->getValidationGroups($request->attributes->get('_controller'));
        }

        return null;
    }
    /**
     * @param Request $request
     *
     * @return PropertiesAwareValidator|null
     */
    public function createPropertiesValidator(Request $request)
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            $pathConverters = $api->getPropertyPathConverters($request->attributes->get('_controller'));
            if ($this->propertyPathConverter !== null) {
                $pathConverters[] = $this->propertyPathConverter;
            }

            $propertyPathConverter = count($pathConverters) > 0
                ? new PathConverter($pathConverters)
                : null
            ;

            return new PropertiesAwareValidator($this->validator, $propertyPathConverter);
        }

        return null;
    }

    /**
     * returns if controlers request body should be logged or not
     *
     * @param Request $request
     *
     * @return array|null
     */
    public function getRequestLoggingParts(Request $request)
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $api->getRequestLoggingParts($request->attributes->get('_controller'));
        }

        return null;
    }

    /**
     * Returns request query mapper for this request
     *
     * @param Request $request
     *
     * @return AttributeResolverInterface[]
     */
    public function getRequestAttributeResolvers(Request $request)
    {
        $requestAttrResolvers = array();

        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            $requestAttrResolvers  = $api->getRequestAttributeResolvers($request->attributes->get('_controller'));
        }

        return $requestAttrResolvers;
    }

    /**
     * Returns response mapper for this request
     *
     * @param Request $request
     * @param array   $options
     *
     * @return NormalizerInterface|null
     */
    public function getResponseMapper(Request $request, array $options = array())
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $api->getResponseMapper($request->attributes->get('_controller'), $options);
        }

        return null;
    }

    /**
     * Returns cache strategy for this request
     *
     * @param Request $request
     * @param array   $options
     *
     * @return CacheStrategyInterface|null
     */
    public function getCacheStrategy(Request $request, array $options = array())
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $api->getCacheStrategy($request->attributes->get('_controller'), $options);
        }

        return null;
    }

    /**
     * Returns encoder for this request
     *
     * @param Request $request
     * @param array   $options
     *
     * @return EncoderInterface|null
     */
    public function getEncoder(Request $request, array $options = array())
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $this->getEncoderForApi($request, $api, $options);
        }

        return null;
    }

    /**
     * Returns decoder for this request
     *
     * @param Request $request
     *
     * @return DecoderInterface|null
     */
    public function getDecoder(Request $request)
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $this->getDecoderForApi($request, $api);
        }

        return null;
    }

    /**
     * Returns security strategy for this request
     *
     * @param Request $request
     *
     * @return SecurityStrategyInterface|null
     */
    public function getSecurityStrategy(Request $request)
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $api->getSecurityStrategy();
        }

        return null;
    }

    /**
     * @param PropertyPathConverterInterface|null $propertyPathConverter
     *
     * @return $this
     */
    public function setPropertyPathConverter($propertyPathConverter)
    {
        $this->propertyPathConverter = $propertyPathConverter;

        return $this;
    }

    public function getLogger(Request $request)
    {
        $api = $this->requestApiResolver->getApiForRequest($request);
        if ($api !== null) {
            return $api->getLogger();
        }

        return null;
    }

    /**
     * Creates Error entity from given exception
     *
     * @param Exception $exception
     *
     * @return Error
     */
    protected function createErrorFromException(Exception $exception)
    {
        if ($exception instanceof ApiException) {
            return Error::create()
                ->setCode($exception->getErrorCode())
                ->setMessage($exception->getMessage())
                ->setStatusCode($exception->getStatusCode())
                ->setProperties($exception->getProperties())
                ->setData($exception->getData())
                ->setViolations($exception->getViolations())
            ;
        } elseif ($exception instanceof InvalidDataException) {
            return Error::create()
                ->setCode(ApiException::INVALID_PARAMETERS)
                ->setMessage($exception->getMessage())
                ->setStatusCode(400)
                ->setProperties($exception->getProperties())
                ->setViolations($exception->getViolations())
            ;
        } elseif ($exception instanceof AuthenticationCredentialsNotFoundException) {
            return Error::create()->setCode(ApiException::UNAUTHORIZED)->setMessage('No authorization data found');
        } elseif ($exception instanceof AuthenticationException) {
            $error = Error::create()->setCode(ApiException::UNAUTHORIZED);
            if ($exception->getCode() === 999) {
                $error->setMessage($exception->getMessage());
            }
            return $error;
        } elseif ($exception instanceof AccessDeniedException) {
            return Error::create()->setCode(ApiException::FORBIDDEN)->setMessage($exception->getMessage());
        } elseif ($exception instanceof AccessDeniedHttpException) {
            return Error::create()->setCode(ApiException::FORBIDDEN)->setMessage($exception->getMessage());
        } elseif ($exception instanceof ResourceNotFoundException || $exception instanceof NotFoundHttpException) {
            return Error::create()->setCode(ApiException::NOT_FOUND)->setMessage('Provided url not found')->setStatusCode(404);
        } elseif ($exception instanceof MethodNotAllowedException) {
            return Error::create()
                ->setCode(ApiException::NOT_FOUND)
                ->setMessage('Provided method not allowed for this url')
                ->setStatusCode(404)
            ;
        } elseif ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            if ($exception->getStatusCode() === 405 || $exception->getStatusCode() === 404) {
                return Error::create()
                    ->setCode(ApiException::NOT_FOUND)
                    ->setStatusCode($exception->getStatusCode())
                    ->setMessage('Used method is not allowed for this url')
                    ;
            } elseif ($exception->getStatusCode() === 401) {
                return Error::create()->setCode(ApiException::UNAUTHORIZED);
            } elseif ($exception->getStatusCode() === 403) {
                return Error::create()->setCode(ApiException::FORBIDDEN);
            } elseif ($exception->getStatusCode() === 400) {
                return Error::create()->setCode(ApiException::INVALID_REQUEST);
            }
        }

        return Error::create()->setCode(ApiException::INTERNAL_SERVER_ERROR)->setStatusCode(500);
    }

    /**
     * Fills error fields with defaults from configuration. Returns the same error object
     *
     * @param Error   $error
     * @param RestApi $api
     *
     * @return Error
     */
    protected function fillErrorDefaults(Error $error, RestApi $api)
    {
        $config = $this->getErrorConfig($api, $error->getCode());
        if (!$error->getMessage() && isset($config['message'])) {
            $error->setMessage($config['message']);
        }
        if (!$error->getUri() && isset($config['uri'])) {
            $error->setUri($config['uri']);
        }
        if (!$error->getStatusCode() && isset($config['statusCode'])) {
            $error->setStatusCode($config['statusCode']);
        }

        return $error;
    }

    /**
     * Returns encoder for this request and API
     *
     * @param Request $request
     * @param RestApi $api
     * @param array   $options
     *
     * @return EncoderInterface
     * @throws Exception|ConfigurationException
     */
    protected function getEncoderForApi(Request $request, RestApi $api, array $options = array())
    {
        $format = $this->formatDetector->getResponseFormat($request, $api->getAvailableResponseFormats());
        $encoder = $api->getEncoder($format, $options);
        if ($encoder === null) {
            if (!isset($this->encoders[$format])) {
                throw new ConfigurationException('Format is not supported: ' . $format);
            }
            $encoder = $this->encoders[$format];
        }
        return $encoder;
    }

    /**
     * Returns decoder for this request and API
     *
     * @param Request $request
     * @param RestApi $api
     *
     * @return DecoderInterface
     * @throws Exception|ConfigurationException
     */
    protected function getDecoderForApi(Request $request, RestApi $api)
    {
        $format = $this->formatDetector->getRequestFormat($request, $api->getAvailableRequestFormats());
        $decoder = $api->getDecoder($format);
        if ($decoder === null) {
            if (!isset($this->decoders[$format])) {
                throw new ConfigurationException('Format is not supported: ' . $format);
            }
            $decoder = $this->decoders[$format];
        }
        return $decoder;
    }

    /**
     * Returns merged configuration for error code
     *
     * @param RestApi $api
     * @param string  $errorCode
     *
     * @return array    available keys: statusCode, message, uri; value can be null
     */
    protected function getErrorConfig(RestApi $api, $errorCode)
    {
        $config = $api->getErrorConfig($errorCode);
        if ($config === null) {
            $config = array();
        }
        $globalConfig = $this->errorConfig->getConfig($errorCode) ?: array('statusCode' => 400);
        foreach ($globalConfig as $key => $value) {
            if (!isset($config[$key])) {
                $config[$key] = $value;
            }
        }
        return $config;
    }
}
