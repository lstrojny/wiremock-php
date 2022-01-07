<?php

namespace WireMock\Client;

use DateTime;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use WireMock\Fault\DelayDistribution;
use WireMock\Fault\GlobalDelaySettings;
use WireMock\Matching\RequestPattern;
use WireMock\Matching\UrlMatchingStrategy;
use WireMock\PostServe\WebhookDefinition;
use WireMock\Recording\RecordingStatusResult;
use WireMock\Recording\RecordSpecBuilder;
use WireMock\Recording\SnapshotRecordResult;
use WireMock\Serde\SerializerFactory;
use WireMock\Stubbing\StubImport;
use WireMock\Stubbing\StubImportBuilder;
use WireMock\Stubbing\StubMapping;
use WireMock\Verification\CountMatchingStrategy;

class WireMock
{
    /** @var string */
    private $_hostname;
    /** @var int */
    private $_port;
    /** @var HttpWait */
    private $_httpWait;
    /** @var Curl  */
    private $_curl;
    /** @var Serializer */
    private $_serializer;

    public static function create($hostname = 'localhost', $port = 8080)
    {
        $httpWait = new HttpWait();
        $curl = new Curl();

        $serializer = SerializerFactory::default();
        return new self($httpWait, $curl, $serializer, $hostname, $port);
    }

    public function __construct(
        HttpWait $httpWait,
        Curl $curl,
        SerializerInterface $serializer,
        $hostname = 'localhost',
        $port = 8080
    ) {
        $this->_hostname = $hostname;
        $this->_port = $port;
        $this->_httpWait = $httpWait;
        $this->_curl = $curl;
        $this->_serializer = $serializer;
    }

    public function isAlive($timeoutSecs = 10, $debug = true)
    {
        $url = $this->_makeUrl('__admin/');
        return $this->_httpWait->waitForServerToGive200($url, $timeoutSecs, $debug);
    }

    public function isShutDown()
    {
        $url = $this->_makeUrl('__admin/');
        return $this->_httpWait->waitForServerToFailToRespond($url);
    }

    public function stubFor(MappingBuilder $mappingBuilder)
    {
        $stubMapping = $mappingBuilder->build();
        /** @var StubMapping $responseMapping */
        $responseMapping = $this->doPost('__admin/mappings', $stubMapping, StubMapping::class);
        $stubMapping->setId($responseMapping->getId());
        return $stubMapping;
    }

    public function editStub(MappingBuilder $mappingBuilder)
    {
        $stubMapping = $mappingBuilder->build();
        if (!$stubMapping->getId()) {
            throw new VerificationException('Cannot edit a stub without an id');
        }
        $url = $this->_makeUrl('__admin/mappings/' . urlencode($stubMapping->getId()));
        $requestJson = $this->_serializer->serialize($stubMapping, 'json');
        $this->_curl->put($url, $requestJson);
        return $stubMapping;
    }

    /**
     * @param StubImport|StubImportBuilder $stubImportsOrBuilder
     */
    public function importStubs($stubImportsOrBuilder)
    {
        $stubImports = ($stubImportsOrBuilder instanceof StubImport) ?
            $stubImportsOrBuilder :
            $stubImportsOrBuilder->build();
        $this->doPost('__admin/mappings/import', $stubImports);
    }

    /**
     * @param RequestPatternBuilder|CountMatchingStrategy|integer $requestPatternBuilderOrCount
     * @param RequestPatternBuilder|null $requestPatternBuilder
     * @throws ClientException
     * @throws VerificationException
     */
    public function verify($requestPatternBuilderOrCount, RequestPatternBuilder $requestPatternBuilder = null)
    {
        if ($requestPatternBuilderOrCount instanceof CountMatchingStrategy) {
            $patternBuilder = $requestPatternBuilder;
            $numberOfRequestsMatcher = $requestPatternBuilderOrCount;
        } else if (is_int($requestPatternBuilderOrCount)) {
            $patternBuilder = $requestPatternBuilder;
            $numberOfRequestsMatcher = self::exactly($requestPatternBuilderOrCount);
        } else {
            $patternBuilder = $requestPatternBuilderOrCount;
            $numberOfRequestsMatcher = null;
        }

        $requestPattern = $patternBuilder->build();
        $response = $this->doPost('__admin/requests/count', $requestPattern, CountMatchingRequestsResult::class);
        $count = $response->getCount();

        if ($numberOfRequestsMatcher === null) {
            // If $numberOfRequestsMatcher is not specified, any non-zero number of requests is acceptable
            if ($count < 1) {
                throw new VerificationException("Expected at least one request, but found $count");
            }
        } else {
            if (!$numberOfRequestsMatcher->matches($count)) {
                $describe = $numberOfRequestsMatcher->describe();
                throw new VerificationException("Expected $describe request(s), but found $count");
            }
        }
    }

    /**
     * getAllServeEvents can be called in a variety of ways:
     * - with no parameters, to return all serve events
     * - with an optional DateTime as the first param and an optional int as the second param to specify
     *   since and limit, for pagination
     * - with a ServeEventQuery (since 2.32) which provides additional controls
     *
     * @param ServeEventQuery|DateTime|null $sinceOrQuery
     * @param int|null $limit
     * @return GetServeEventsResult
     */
    public function getAllServeEvents($sinceOrQuery = null, $limit = null)
    {
        $pathAndParams = '__admin/requests';

        if ($sinceOrQuery instanceof ServeEventQuery) {
            $query = $sinceOrQuery;
        } else {
            $query = ServeEventQuery::paginated($sinceOrQuery, $limit);
        }

        $params = $query->toParamsString();
        if ($params != '') {
            $pathAndParams .= '?' . $params;
        }

        $url = $this->_makeUrl($pathAndParams);
        $responseJson = $this->_curl->get($url);
        return $this->_serializer->deserialize($responseJson, GetServeEventsResult::class, 'json');
    }

    /**
     * @param RequestPatternBuilder $requestPatternBuilder
     * @return LoggedRequest[]
     */
    public function findAll(RequestPatternBuilder $requestPatternBuilder)
    {
        $requestPattern = $requestPatternBuilder->build();
        $result = $this->doPost('__admin/requests/find', $requestPattern, FindRequestsResult::class);
        return $result->getRequests();
    }

    /**
     * @return UnmatchedRequests
     */
    public function findUnmatchedRequests()
    {
        $url = $this->_makeUrl('__admin/requests/unmatched');
        $resultJson = $this->_curl->get($url);
        /** @var UnmatchedRequests $result */
        $result = $this->_serializer->deserialize($resultJson, UnmatchedRequests::class, 'json');
        return $result;
    }

    /**
     * @param LoggedRequest|RequestPattern $loggedRequestOrPattern
     * @return FindNearMissesResult
     * @throws \Exception
     */
    public function findNearMissesFor($loggedRequestOrPattern)
    {
        if ($loggedRequestOrPattern instanceof LoggedRequest) {
            $path = '__admin/near-misses/request';
        } else if ($loggedRequestOrPattern instanceof RequestPatternBuilder) {
            $loggedRequestOrPattern = $loggedRequestOrPattern->build();
            $path = '__admin/near-misses/request-pattern';
        } else {
            throw new \Exception('Unexpected near miss specifier: ' . print_r($loggedRequestOrPattern, true));
        }
        /** @var FindNearMissesResult $result */
        $result = $this->doPost($path, $loggedRequestOrPattern, FindNearMissesResult::class);
        return $result;
    }

    /**
     * @return FindNearMissesResult
     */
    public function findNearMissesForAllUnmatched()
    {
        $url = $this->_makeUrl('__admin/requests/unmatched/near-misses');
        $findResultJson = $this->_curl->get($url);
        /** @var FindNearMissesResult $result */
        $result = $this->_serializer->deserialize($findResultJson, FindNearMissesResult::class, 'json');
        return $result;
    }

    /**
     * Deletes all serve events from the WireMock server's request journal
     */
    public function resetAllRequests()
    {
        $url = $this->_makeUrl('__admin/requests');
        $this->_curl->delete($url);
    }

    /**
     * Removes specified serve event from the WireMock server's request journal
     * @param string $id
     */
    public function removeServeEvent($id)
    {
        $url = $this->_makeUrl("__admin/requests/$id");
        $this->_curl->delete($url);
    }

    /**
     * @param RequestPatternBuilder $requestPatternBuilder
     */
    public function removeServeEvents($requestPatternBuilder)
    {
        $this->doPost('__admin/requests/remove', $requestPatternBuilder->build());
    }

    /**
     * @param ValueMatchingStrategy $valueMatchingStrategy
     */
    public function removeEventsByStubMetadata($valueMatchingStrategy)
    {
        $this->doPost('__admin/requests/remove-by-metadata', $valueMatchingStrategy);
    }

    /**
     * Sets a delay on all stubbed responses
     *
     * @param int $delayMillis
     */
    public function setGlobalFixedDelay($delayMillis)
    {
        $this->doPost('__admin/settings', GlobalDelaySettings::fixed($delayMillis));
    }

    /**
     * @param DelayDistribution $delayDistribution
     */
    public function setGlobalRandomDelay($delayDistribution)
    {
        $this->doPost('__admin/settings', GlobalDelaySettings::random($delayDistribution));
    }

    /**
     * Note: this function isn't part of the Java API
     */
    public function resetGlobalDelays()
    {
        $this->doPost('__admin/settings', GlobalDelaySettings::none());
    }

    public function saveAllMappings()
    {
        $this->doPost('__admin/mappings/save');
    }

    /**
     * Deletes a particular stub, identified by it's GUID, from the server
     *
     * @param string $id A string representation of a GUID
     */
    public function removeStub($id)
    {
        $url = $this->_makeUrl('__admin/mappings/' . urlencode($id));
        $this->_curl->delete($url);
    }

    /**
     * Reset all stubbings and the request journal
     */
    public function reset()
    {
        $this->doPost('__admin/reset');
    }

    /**
     * Reset all stubbings, reload those from the mappings directory, and reset the request journal
     */
    public function resetToDefault()
    {
        $this->doPost('__admin/mappings/reset');
    }

    /**
     * @return GetScenariosResult
     */
    public function getAllScenarios()
    {
        $url = $this->_makeUrl('__admin/scenarios');
        $findResultJson = $this->_curl->get($url);
        /** @var GetScenariosResult $findResult */
        $findResult = $this->_serializer->deserialize($findResultJson, GetScenariosResult::class, 'json');
        return $findResult;
    }

    /**
     * Reset all scenarios to the Scenario.STARTED state
     */
    public function resetAllScenarios()
    {
        $this->doPost('__admin/scenarios/reset');
    }

    public function shutdownServer()
    {
        $this->doPost('__admin/shutdown');
    }

    /**
     * @param int $limit
     * @param int $offset
     * @return ListStubMappingsResult
     */
    public function listAllStubMappings($limit = null, $offset = null)
    {
        $pathAndParams = '__admin/mappings';
        if ($limit || $offset) {
            $pathAndParams .= '?';
            if ($limit) {
                $pathAndParams .= 'limit=' . urlencode($limit);
            }
            if ($limit && $offset) {
                $pathAndParams .= '&';
            }
            if ($offset) {
                $pathAndParams .= 'offset=' . urlencode($offset);
            }
        }
        $url = $this->_makeUrl($pathAndParams);
        $resultJson = $this->_curl->get($url);
        /** @var ListStubMappingsResult $result */
        $result = $this->_serializer->deserialize($resultJson, ListStubMappingsResult::class, 'json');
        return $result;
    }

    /**
     * @param string $id GUID of stub to retrieve
     * @return StubMapping
     * @throws \Exception
     */
    public function getSingleStubMapping($id)
    {
        $url = $this->_makeUrl('__admin/mappings/' . urlencode($id));
        $resultJson = $this->_curl->get($url);
        /** @var StubMapping $result */
        $result = $this->_serializer->deserialize($resultJson, StubMapping::class, 'json');
        return $result;
    }

    /**
     * @param ValueMatchingStrategy $valueMatchingStrategy
     * @return ListStubMappingsResult
     */
    public function findStubsByMetadata($valueMatchingStrategy)
    {
        return $this->doPost('__admin/mappings/find-by-metadata', $valueMatchingStrategy, ListStubMappingsResult::class);
    }

    /**
     * @param ValueMatchingStrategy $valueMatchingStrategy
     */
    public function removeStubsByMetadata($valueMatchingStrategy)
    {
        $this->doPost('__admin/mappings/remove-by-metadata', $valueMatchingStrategy);
    }

    /**
     * @param RecordSpecBuilder|string $recordingSpecOrUrl
     */
    public function startRecording($recordingSpecOrUrl)
    {
        if (is_string($recordingSpecOrUrl)) {
            $spec = self::recordSpec()->forTarget($recordingSpecOrUrl)->build();
        } else {
            $spec = $recordingSpecOrUrl->build();
        }

        $this->doPost('__admin/recordings/start', $spec);
    }

    /**
     * @return RecordingStatusResult
     */
    public function getRecordingStatus()
    {
        $url = $this->_makeUrl('__admin/recordings/status');
        $resultJson = $this->_curl->get($url);
        /** @var RecordingStatusResult $result */
        $result = $this->_serializer->deserialize($resultJson, RecordingStatusResult::class, 'json');
        return $result;
    }

    /**
     * @return SnapshotRecordResult
     */
    public function stopRecording()
    {
        return $this->doPost('__admin/recordings/stop', null, SnapshotRecordResult::class);
    }

    /**
     * @param RecordSpecBuilder $recordingSpecBuilder
     * @return SnapshotRecordResult
     */
    public function snapshotRecord($recordingSpecBuilder = null)
    {
        $recordingSpec = $recordingSpecBuilder ? $recordingSpecBuilder->build() : null;
        return $this->doPost('__admin/recordings/snapshot', $recordingSpec, SnapshotRecordResult::class);
    }

    /**
     * @param string $path
     * @param null $body
     * @param string|null $resultType
     * @return mixed
     * @throws ClientException
     */
    private function doPost(string $path, $body = null, ?string $resultType = null)
    {
        $url = $this->_makeUrl($path);
        if ($body != null) {
            $requestJson = $this->_serializer->serialize($body, 'json');
        } else {
            $requestJson = null;
        }
        $resultJson = $this->_curl->post($url, $requestJson);
        if ($resultType != null) {
            return $this->_serializer->deserialize($resultJson, $resultType, 'json');
        } else {
            return null;
        }
    }

    private function _makeUrl($path)
    {
        return "http://$this->_hostname:$this->_port/$path";
    }

    /**
     * @param UrlMatchingStrategy|string $urlMatchingStrategy
     * @return MappingBuilder
     */
    public static function get($urlMatchingStrategy)
    {
        if (is_string($urlMatchingStrategy)) {
            $urlMatchingStrategy = self::urlEqualTo($urlMatchingStrategy);
        }
        $requestPattern = new RequestPatternBuilder('GET', $urlMatchingStrategy);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param UrlMatchingStrategy|string $urlMatchingStrategy
     * @return MappingBuilder
     */
    public static function post($urlMatchingStrategy)
    {
        if (is_string($urlMatchingStrategy)) {
            $urlMatchingStrategy = self::urlEqualTo($urlMatchingStrategy);
        }
        $requestPattern = new RequestPatternBuilder('POST', $urlMatchingStrategy);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param UrlMatchingStrategy|string $urlMatchingStrategy
     * @return MappingBuilder
     */
    public static function put($urlMatchingStrategy)
    {
        if (is_string($urlMatchingStrategy)) {
            $urlMatchingStrategy = self::urlEqualTo($urlMatchingStrategy);
        }
        $requestPattern = new RequestPatternBuilder('PUT', $urlMatchingStrategy);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param UrlMatchingStrategy|string $urlMatchingStrategy
     * @return MappingBuilder
     */
    public static function delete($urlMatchingStrategy)
    {
        if (is_string($urlMatchingStrategy)) {
            $urlMatchingStrategy = self::urlEqualTo($urlMatchingStrategy);
        }
        $requestPattern = new RequestPatternBuilder('DELETE', $urlMatchingStrategy);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param UrlMatchingStrategy|string $urlMatchingStrategy
     * @return MappingBuilder
     */
    public static function patch($urlMatchingStrategy)
    {
        if (is_string($urlMatchingStrategy)) {
            $urlMatchingStrategy = self::urlEqualTo($urlMatchingStrategy);
        }
        $requestPattern = new RequestPatternBuilder('PATCH', $urlMatchingStrategy);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param UrlMatchingStrategy|string $urlMatchingStrategy
     * @return MappingBuilder
     */
    public static function head($urlMatchingStrategy)
    {
        if (is_string($urlMatchingStrategy)) {
            $urlMatchingStrategy = self::urlEqualTo($urlMatchingStrategy);
        }
        $requestPattern = new RequestPatternBuilder('HEAD', $urlMatchingStrategy);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param UrlMatchingStrategy|string $urlMatchingStrategy
     * @return MappingBuilder
     */
    public static function options($urlMatchingStrategy)
    {
        if (is_string($urlMatchingStrategy)) {
            $urlMatchingStrategy = self::urlEqualTo($urlMatchingStrategy);
        }
        $requestPattern = new RequestPatternBuilder('OPTIONS', $urlMatchingStrategy);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param UrlMatchingStrategy|string $urlMatchingStrategy
     * @return MappingBuilder
     */
    public static function trace($urlMatchingStrategy)
    {
        if (is_string($urlMatchingStrategy)) {
            $urlMatchingStrategy = self::urlEqualTo($urlMatchingStrategy);
        }
        $requestPattern = new RequestPatternBuilder('TRACE', $urlMatchingStrategy);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param UrlMatchingStrategy|string $urlMatchingStrategy
     * @return MappingBuilder
     */
    public static function any($urlMatchingStrategy)
    {
        if (is_string($urlMatchingStrategy)) {
            $urlMatchingStrategy = self::urlEqualTo($urlMatchingStrategy);
        }
        $requestPattern = new RequestPatternBuilder('ANY', $urlMatchingStrategy);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param string $matcherName
     * @param array $params
     * @return MappingBuilder
     */
    public static function requestMatching($matcherName, $params = array())
    {
        $requestPattern = new RequestPatternBuilder($matcherName, $params);
        return new MappingBuilder($requestPattern);
    }

    /**
     * @param string $url
     * @return UrlMatchingStrategy
     */
    public static function urlEqualTo($url)
    {
        return new UrlMatchingStrategy('url', $url);
    }

    /**
     * @param string $urlRegex
     * @return UrlMatchingStrategy
     */
    public static function urlMatching($urlRegex)
    {
        return new UrlMatchingStrategy('urlPattern', $urlRegex);
    }

    /**
     * @param string $urlPath
     * @return UrlMatchingStrategy
     */
    public static function urlPathEqualTo($urlPath)
    {
        return new UrlMatchingStrategy('urlPath', $urlPath);
    }

    /**
     * @param string $urlPathRegex
     * @return UrlMatchingStrategy
     */
    public static function urlPathMatching($urlPathRegex)
    {
        return new UrlMatchingStrategy('urlPathPattern', $urlPathRegex);
    }

    /**
     * @return UrlMatchingStrategy
     */
    public static function anyUrl()
    {
        return new UrlMatchingStrategy('urlPattern', '.*');
    }

    /**
     * @param string $value
     * @return EqualToMatchingStrategy
     */
    public static function equalTo($value)
    {
        return new EqualToMatchingStrategy($value);
    }

    /**
     * @param string $value
     * @return EqualToMatchingStrategy
     */
    public static function equalToIgnoreCase($value)
    {
        return new EqualToMatchingStrategy($value, true);
    }

    /**
     * @param string $base64String
     * @return ValueMatchingStrategy
     */
    public static function binaryEqualTo($base64String)
    {
        return new ValueMatchingStrategy('binaryEqualTo', $base64String);
    }

    /**
     * @param string $value
     * @return ValueMatchingStrategy
     */
    public static function matching($value)
    {
        return new ValueMatchingStrategy('matches', $value);
    }

    /**
     * @param string $value
     * @return ValueMatchingStrategy
     */
    public static function notMatching($value)
    {
        return new ValueMatchingStrategy('doesNotMatch', $value);
    }

    /**
     * @param string $value
     * @return ValueMatchingStrategy
     */
    public static function containing($value)
    {
        return new ValueMatchingStrategy('contains', $value);
    }

    /**
     * @param string $value
     * @param boolean $ignoreArrayOrder
     * @param boolean $ignoreExtraElements
     * @return ValueMatchingStrategy
     */
    public static function equalToJson($value, $ignoreArrayOrder = null, $ignoreExtraElements = null)
    {
        return new JsonValueMatchingStrategy($value, $ignoreArrayOrder, $ignoreExtraElements);
    }

    /**
     * @param string $jsonPath
     * @param ValueMatchingStrategy $valueMatchingStrategy
     * @return JsonPathValueMatchingStrategy
     */
    public static function matchingJsonPath($jsonPath, $valueMatchingStrategy = null)
    {
        return new JsonPathValueMatchingStrategy($jsonPath, $valueMatchingStrategy);
    }

    /**
     * @param string $xml
     * @param bool $enablePlaceholders
     * @param string $placeholderOpeningDelimiterRegex
     * @param string $placeholderClosingDelimiterRegex
     * @return EqualToXmlMatchingStrategy
     */
    public static function equalToXml($xml,
        $enablePlaceholders = false,
        $placeholderOpeningDelimiterRegex = null,
        $placeholderClosingDelimiterRegex = null
    ) {
        return new EqualToXmlMatchingStrategy(
            $xml,
            $enablePlaceholders,
            $placeholderOpeningDelimiterRegex,
            $placeholderClosingDelimiterRegex
        );
    }

    /**
     * @param string $xPath
     * @param ValueMatchingStrategy $valueMatchingStrategy
     * @return XPathValueMatchingStrategy
     */
    public static function matchingXPath($xPath, $valueMatchingStrategy = null)
    {
        return new XPathValueMatchingStrategy($xPath, $valueMatchingStrategy);
    }

    /**
     * @return MultipartValuePatternBuilder
     */
    public static function aMultipart()
    {
        return new MultipartValuePatternBuilder();
    }

    /**
     * @return ValueMatchingStrategy
     */
    public static function absent()
    {
        return new ValueMatchingStrategy('absent', true);
    }

    /**
     * @param int $count
     * @return CountMatchingStrategy
     */
    public static function lessThan($count)
    {
        return CountMatchingStrategy::lessThan($count);
    }

    /**
     * @param int $count
     * @return CountMatchingStrategy
     */
    public static function lessThanOrExactly($count)
    {
        return CountMatchingStrategy::lessThanOrExactly($count);
    }

    /**
     * @param int $count
     * @return CountMatchingStrategy
     */
    public static function exactly($count)
    {
        return CountMatchingStrategy::exactly($count);
    }

    /**
     * @param int $count
     * @return CountMatchingStrategy
     */
    public static function moreThanOrExactly($count)
    {
        return CountMatchingStrategy::moreThanOrExactly($count);
    }

    /**
     * @param int $count
     * @return CountMatchingStrategy
     */
    public static function moreThan($count)
    {
        return CountMatchingStrategy::moreThan($count);
    }

    /**
     * @param string $dateTimeSpec
     * @return DateTimeMatchingStrategy
     */
    public static function before($dateTimeSpec)
    {
        return DateTimeMatchingStrategy::before($dateTimeSpec);
    }

    /**
     * @return DateTimeMatchingStrategy
     */
    public static function beforeNow()
    {
        return DateTimeMatchingStrategy::before("now");
    }

    /**
     * @param string $dateTimeSpec
     * @return DateTimeMatchingStrategy
     */
    public static function equalToDateTime($dateTimeSpec)
    {
        return DateTimeMatchingStrategy::equalToDateTime($dateTimeSpec);
    }

    /**
     * @return DateTimeMatchingStrategy
     */
    public static function isNow()
    {
        return DateTimeMatchingStrategy::equalToDateTime("now");
    }

    /**
     * @param string $dateTimeSpec
     * @return DateTimeMatchingStrategy
     */
    public static function after($dateTimeSpec)
    {
        return DateTimeMatchingStrategy::after($dateTimeSpec);
    }

    /**
     * @return DateTimeMatchingStrategy
     */
    public static function afterNow()
    {
        return DateTimeMatchingStrategy::after("now");
    }

    /**
     * @param ValueMatchingStrategy ...$matchers
     * @return LogicalOperatorMatchingStrategy
     */
    public static function and(...$matchers)
    {
        return LogicalOperatorMatchingStrategy::andAll(...$matchers);
    }

    /**
     * @param ValueMatchingStrategy ...$matchers
     * @return LogicalOperatorMatchingStrategy
     */
    public static function or(...$matchers)
    {
        return LogicalOperatorMatchingStrategy::orAll(...$matchers);
    }

    /**
     * @return RecordSpecBuilder
     */
    public static function recordSpec()
    {
        return new RecordSpecBuilder();
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function aResponse()
    {
        return new ResponseDefinitionBuilder();
    }

    /**
     * @param UrlMatchingStrategy $urlMatchingStrategy
     * @return RequestPatternBuilder
     */
    public static function getRequestedFor(UrlMatchingStrategy $urlMatchingStrategy)
    {
        return new RequestPatternBuilder('GET', $urlMatchingStrategy);
    }

    /**
     * @param UrlMatchingStrategy $urlMatchingStrategy
     * @return RequestPatternBuilder
     */
    public static function postRequestedFor(UrlMatchingStrategy $urlMatchingStrategy)
    {
        return new RequestPatternBuilder('POST', $urlMatchingStrategy);
    }

    /**
     * @param UrlMatchingStrategy $urlMatchingStrategy
     * @return RequestPatternBuilder
     */
    public static function putRequestedFor(UrlMatchingStrategy $urlMatchingStrategy)
    {
        return new RequestPatternBuilder('PUT', $urlMatchingStrategy);
    }

    /**
     * @param UrlMatchingStrategy $urlMatchingStrategy
     * @return RequestPatternBuilder
     */
    public static function deleteRequestedFor(UrlMatchingStrategy $urlMatchingStrategy)
    {
        return new RequestPatternBuilder('DELETE', $urlMatchingStrategy);
    }

    /**
     * @param UrlMatchingStrategy $urlMatchingStrategy
     * @return RequestPatternBuilder
     */
    public static function optionsRequestedFor(UrlMatchingStrategy $urlMatchingStrategy)
    {
        return new RequestPatternBuilder('OPTIONS', $urlMatchingStrategy);
    }

    /**
     * @param UrlMatchingStrategy $urlMatchingStrategy
     * @return RequestPatternBuilder
     */
    public static function patchRequestedFor(UrlMatchingStrategy $urlMatchingStrategy)
    {
        return new RequestPatternBuilder('PATCH', $urlMatchingStrategy);
    }

    /**
     * @param UrlMatchingStrategy $urlMatchingStrategy
     * @return RequestPatternBuilder
     */
    public static function headRequestedFor(UrlMatchingStrategy $urlMatchingStrategy)
    {
        return new RequestPatternBuilder('HEAD', $urlMatchingStrategy);
    }

    /**
     * @param UrlMatchingStrategy $urlMatchingStrategy
     * @return RequestPatternBuilder
     */
    public static function traceRequestedFor(UrlMatchingStrategy $urlMatchingStrategy)
    {
        return new RequestPatternBuilder('TRACE', $urlMatchingStrategy);
    }

    /**
     * @param string $body
     * @return ResponseDefinitionBuilder
     */
    public static function ok($body = null)
    {
        if ($body) {
            return self::aResponse()->withStatus(200)->withBody($body);
        } else {
            return self::aResponse()->withStatus(200);
        }
    }

    /**
     * @param string $contentType
     * @param string $body
     * @return ResponseDefinitionBuilder
     */
    public static function okForContentType($contentType, $body)
    {
        return self::aResponse()
            ->withStatus(200)
            ->withHeader('Content-Type', $contentType)
            ->withBody($body);
    }

    /**
     * @param string $body
     * @return ResponseDefinitionBuilder
     */
    public static function okJson($body)
    {
        return self::okForContentType('application/json', $body);
    }

    /**
     * @param string $body
     * @return ResponseDefinitionBuilder
     */
    public static function okXml($body)
    {
        return self::okForContentType('application/xml', $body);
    }

    /**
     * @param string $body
     * @return ResponseDefinitionBuilder
     */
    public static function okTextXml($body)
    {
        return self::okForContentType('text/xml', $body);
    }

    /**
     * @param string $body
     * @param integer $status
     * @return ResponseDefinitionBuilder
     */
    public static function jsonResponse($body, $status) {
        return self::aResponse()
            ->withStatus($status)
            ->withHeader('Content-Type', "application/json")
            ->withBody($body);
    }

    /**
     * @param string $url
     * @return MappingBuilder
     */
    public static function proxyAllTo($url)
    {
        return self::any(self::anyUrl())->willReturn(self::aResponse()->proxiedFrom($url));
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function created() {
        return self::aResponse()->withStatus(201);
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function noContent()
    {
        return self::aResponse()->withStatus(204);
    }

    /**
     * @param string $location
     * @return ResponseDefinitionBuilder
     */
    public static function permanentRedirect($location)
    {
        return self::aResponse()->withStatus(301)->withHeader('Location', $location);
    }

    /**
     * @param string $location
     * @return ResponseDefinitionBuilder
     */
    public static function temporaryRedirect($location)
    {
        return self::aResponse()->withStatus(302)->withHeader('Location', $location);
    }

    /**
     * @param string $location
     * @return ResponseDefinitionBuilder
     */
    public static function seeOther($location)
    {
        return self::aResponse()->withStatus(303)->withHeader('Location', $location);
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function badRequest()
    {
        return self::aResponse()->withStatus(400);
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function badRequestEntity()
    {
        return self::aResponse()->withStatus(422);
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function unauthorized()
    {
        return self::aResponse()->withStatus(401);
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function forbidden()
    {
        return self::aResponse()->withStatus(403);
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function notFound()
    {
        return self::aResponse()->withStatus(404);
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function serverError()
    {
        return self::aResponse()->withStatus(500);
    }

    /**
     * @return ResponseDefinitionBuilder
     */
    public static function serviceUnavailable()
    {
        return self::aResponse()->withStatus(503);
    }

    /**
     * @param int $status
     * @return ResponseDefinitionBuilder
     */
    public static function status($status)
    {
        return self::aResponse()->withStatus($status);
    }

    /**
     * @return StubImportBuilder
     */
    public static function stubImport()
    {
        return new StubImportBuilder();
    }

    public static function webhook(): WebhookDefinition
    {
        return new WebhookDefinition();
    }
}
