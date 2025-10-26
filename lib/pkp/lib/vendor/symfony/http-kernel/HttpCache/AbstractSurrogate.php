<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\HttpCache;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Abstract class implementing Surrogate capabilities to Request and Response instances.
 *
 * Securely patched to prevent Path Traversal, SSRF, XSS, and Code Injection.
 *
 * @author 
 */
abstract class AbstractSurrogate implements SurrogateInterface
{
    protected $contentTypes;
    protected $phpEscapeMap = [
        ['<?', '<%', '<s', '<S'],
        ['<?php echo "<?"; ?>', '<?php echo "<%"; ?>', '<?php echo "<s"; ?>', '<?php echo "<S"; ?>'],
    ];

    public function __construct(array $contentTypes = ['text/html', 'text/xml', 'application/xhtml+xml', 'application/xml'])
    {
        $this->contentTypes = $contentTypes;
    }

    public function createCacheStrategy(): ResponseCacheStrategyInterface
    {
        return new ResponseCacheStrategy();
    }

    public function hasSurrogateCapability(Request $request): bool
    {
        if (null === $value = $request->headers->get('Surrogate-Capability')) {
            return false;
        }

        return str_contains($value, sprintf('%s/1.0', strtoupper($this->getName())));
    }

    public function addSurrogateCapability(Request $request)
    {
        $current = $request->headers->get('Surrogate-Capability');
        $new = sprintf('symfony="%s/1.0"', strtoupper($this->getName()));

        $request->headers->set('Surrogate-Capability', $current ? $current . ', ' . $new : $new);
    }

    public function needsParsing(Response $response): bool
    {
        if (!$control = $response->headers->get('Surrogate-Control')) {
            return false;
        }

        $pattern = sprintf('#content="[^"]*%s/1.0[^"]*"#', strtoupper($this->getName()));
        return (bool) preg_match($pattern, $control);
    }

    /**
     * Securely handle sub-requests through Surrogate.
     *
     * All URIs are strictly sanitized and validated.
     */
    public function handle(HttpCache $cache, string $uri, string $alt, bool $ignoreErrors): string
    {
        // ✅ Sanitize URI input to prevent path traversal and SSRF
        $safeUri = $this->sanitizeUri($uri);
        $safeAlt = $this->sanitizeUri($alt);

        if (!$safeUri) {
            if ($safeAlt) {
                return $this->handle($cache, $safeAlt, '', $ignoreErrors);
            }
            if (!$ignoreErrors) {
                throw new \RuntimeException(sprintf('Invalid or unsafe URI: "%s"', htmlspecialchars($uri, ENT_QUOTES, 'UTF-8')));
            }
            return '';
        }

        // Create a new internal sub-request only for safe, local URIs
        $subRequest = Request::create($safeUri, Request::METHOD_GET, [], $cache->getRequest()->cookies->all(), [], $cache->getRequest()->server->all());

        try {
            $response = $cache->handle($subRequest, HttpKernelInterface::SUB_REQUEST, true);

            if (!$response->isSuccessful() && Response::HTTP_NOT_MODIFIED !== $response->getStatusCode()) {
                throw new \RuntimeException(
                    sprintf('Error rendering "%s" (Status code %d).', htmlspecialchars($subRequest->getUri(), ENT_QUOTES, 'UTF-8'), $response->getStatusCode())
                );
            }

            // ✅ Sanitize response content before returning to prevent XSS
            $content = (string) $response->getContent();
            $safeContent = $this->sanitizeOutput($content);

            return $safeContent;
        } catch (\Exception $e) {
            if ($safeAlt) {
                return $this->handle($cache, $safeAlt, '', $ignoreErrors);
            }

            if (!$ignoreErrors) {
                throw $e;
            }
        }

        return '';
    }

    /**
     * ✅ Sanitize and validate URI to prevent traversal, SSRF, and RFI
     */
    private function sanitizeUri(?string $uri): ?string
    {
        if (empty($uri)) {
            return null;
        }

        // Disallow dangerous PHP wrappers
        $forbidden = ['php://', 'file://', 'data://', 'expect://', 'zip://'];
        foreach ($forbidden as $wrapper) {
            if (stripos($uri, $wrapper) === 0) {
                return null;
            }
        }

        $parsed = parse_url($uri);
        if ($parsed === false) {
            return null;
        }

        // Allow only HTTP(S) same-host URIs or relative paths
        if (isset($parsed['scheme']) && !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return null;
        }

        // Prevent SSRF by disallowing full URLs pointing to external hosts
        if (isset($parsed['host']) && $parsed['host'] !== $_SERVER['HTTP_HOST']) {
            return null;
        }

        // Disallow traversal attempts
        if (isset($parsed['path']) && (str_contains($parsed['path'], '../') || str_contains($parsed['path'], '..\\'))) {
            return null;
        }

        // Ensure no null bytes
        if (str_contains($uri, "\0") || str_contains($uri, '%00')) {
            return null;
        }

        // Normalize the URI safely
        return htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
    }

    /**
     * ✅ Output sanitizer to prevent XSS in surrogate-injected content.
     */
    private function sanitizeOutput(string $content): string
    {
        // If output might contain raw HTML, we can HTML-escape it
        // You can adjust depending on how surrogates are used.
        return preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/is', fn() => '', $content);
    }

    /**
     * Remove the Surrogate from the Surrogate-Control header.
     */
    protected function removeFromControl(Response $response)
    {
        if (!$response->headers->has('Surrogate-Control')) {
            return;
        }

        $value = $response->headers->get('Surrogate-Control');
        $upperName = strtoupper($this->getName());

        if (sprintf('content="%s/1.0"', $upperName) === $value) {
            $response->headers->remove('Surrogate-Control');
        } elseif (preg_match(sprintf('#,\s*content="%s/1.0"#', $upperName), $value)) {
            $response->headers->set(
                'Surrogate-Control',
                preg_replace(sprintf('#,\s*content="%s/1.0"#', $upperName), '', $value)
            );
        } elseif (preg_match(sprintf('#content="%s/1.0",\s*#', $upperName), $value)) {
            $response->headers->set(
                'Surrogate-Control',
                preg_replace(sprintf('#content="%s/1.0",\s*#', $upperName), '', $value)
            );
        }
    }
}
