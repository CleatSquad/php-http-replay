<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\PhpVcr;

use CleatSquad\HttpReplay\Internal\DecodedValue;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Yaml\Yaml;

final class PhpVcrCassetteImporter
{
    /**
     * Imports a PHP-VCR YAML cassette file or string into a native Cassette instance.
     *
     * @param string $content YAML content string or filepath
     */
    public static function fromYaml(string $content): Cassette
    {
        if (is_file($content)) {
            $content = (string) file_get_contents($content);
        }

        $parsed = Yaml::parse($content);
        if (!is_array($parsed)) {
            return new Cassette(1, []);
        }

        $exchanges = [];
        foreach ($parsed as $recording) {
            if (!is_array($recording) || !isset($recording['request'], $recording['response'])) {
                continue;
            }

            $reqData = is_array($recording['request']) ? $recording['request'] : [];
            $resData = is_array($recording['response']) ? $recording['response'] : [];

            $method = DecodedValue::asString($reqData['method'] ?? null, 'GET');
            $url = DecodedValue::asString($reqData['url'] ?? null);
            $reqBody = isset($reqData['body']) ? DecodedValue::asString($reqData['body']) : null;
            $reqHeaders = DecodedValue::asHeaders($reqData['headers'] ?? null);

            $request = new Request($method, $url, $reqHeaders, $reqBody);

            $statusData = $resData['status'] ?? null;
            $statusCode = is_array($statusData)
                ? DecodedValue::asInt($statusData['code'] ?? null, 200)
                : DecodedValue::asInt($statusData, 200);

            $resBody = isset($resData['body']) ? DecodedValue::asString($resData['body']) : null;
            $resHeaders = DecodedValue::asHeaders($resData['headers'] ?? null);

            $response = new Response($statusCode, $resHeaders, $resBody);

            $exchanges[] = new Exchange($request, $response);
        }

        return new Cassette(1, $exchanges);
    }
}
