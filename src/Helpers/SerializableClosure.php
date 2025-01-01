<?php

namespace OnlyPHP\Helpers;

if (!class_exists('SerializableClosure')) {
    class SerializableClosure
    {
        /**
         * @var \Closure The closure instance being serialized
         */
        private $closure;

        /**
         * @var \ReflectionFunction Reflection information about the closure
         */
        private $reflection;

        /**
         * @var string The code representation of the closure
         */
        private $code;

        /**
         * @var array The captured scope of the closure
         */
        private $scope;

        /**
         * @var string A hash signature to verify data integrity
         */
        private $signature;

        /**
         * Constructor: Initializes the SerializableClosure instance.
         *
         * @param \Closure $closure The closure to be serialized
         */
        public function __construct(\Closure $closure)
        {
            $this->closure = $closure;
            $this->reflection = new \ReflectionFunction($closure);
            $this->code = $this->sanitizeCode($this->extractCode());
            $this->scope = $this->captureScope();
            $this->signature = $this->generateSignature();
        }

        /**
         * Serializes the closure object.
         *
         * @return array The serialized data
         */
        public function __serialize(): array
        {
            return [
                'code' => $this->code,
                'scope' => $this->scope,
                'signature' => $this->signature,
            ];
        }

        /**
         * Unserializes the closure object and reconstructs it.
         *
         * @param array $data The serialized data
         * @throws \Exception If signature verification fails
         */
        public function __unserialize(array $data): void
        {
            $this->code = $data['code'];
            $this->scope = $data['scope'];
            $this->signature = $data['signature'];

            if (!$this->verifySignature()) {
                throw new \Exception('Signature verification failed. The closure might have been tampered with.');
            }

            $this->reconstructClosure();
        }

        /**
         * Extracts the code of the closure from its source file.
         *
         * @return string The raw closure code
         */
        private function extractCode(): string
        {
            $file = $this->reflection->getFileName();
            $start = $this->reflection->getStartLine() - 1;
            $end = $this->reflection->getEndLine();
            $source = file($file);

            return implode('', array_slice($source, $start, $end - $start));
        }

        /**
         * Sanitizes the extracted code by removing PHP tags and trimming whitespace.
         *
         * @param string $code The raw code
         * @return string The sanitized code
         */
        private function sanitizeCode(string $code): string
        {
            $code = trim($code);
            $code = preg_replace('/<\?php|\?>/', '', $code);
            return $code;
        }

        /**
         * Captures the scope (variables and binding) of the closure.
         *
         * @return array The scope data
         */
        private function captureScope(): array
        {
            $scope = [];
            if ($this->reflection->getClosureThis()) {
                $scope['this'] = $this->reflection->getClosureThis();
            }

            $staticVariables = $this->reflection->getStaticVariables();
            if (!empty($staticVariables)) {
                $scope['variables'] = $staticVariables;
            }

            return $scope;
        }

        /**
         * Reconstructs the closure from the serialized code and scope.
         *
         * @throws \Exception If the code is invalid
         */
        private function reconstructClosure(): void
        {
            $scopeVars = $this->scope['variables'] ?? [];
            $bindTo = $this->scope['this'] ?? null;

            // Evaluate the closure code
            $closure = @eval ('return ' . $this->code . ';');
            if (!($closure instanceof \Closure)) {
                throw new \Exception('Failed to reconstruct the closure. The code might be invalid.');
            }

            // Bind the scope to the closure
            if ($bindTo) {
                $closure = $closure->bindTo($bindTo);
            }

            $this->closure = $closure;
        }

        /**
         * Generates a signature hash for the closure's code and scope.
         *
         * @return string The hash signature
         */
        private function generateSignature(): string
        {
            $data = json_encode([
                'code' => $this->code,
                'scope' => $this->scope,
            ]);
            return hash_hmac('sha256', $data, $this->getSecretKey());
        }

        /**
         * Verifies the integrity of the serialized data using the signature.
         *
         * @return bool True if the signature is valid, false otherwise
         */
        private function verifySignature(): bool
        {
            $expectedSignature = $this->generateSignature();
            return hash_equals($expectedSignature, $this->signature);
        }

        /**
         * Provides a secret key for signing and verifying closures.
         * The key is based on the namespace to ensure uniqueness.
         *
         * @return string The dynamically generated secret key
         */
        private function getSecretKey(): string
        {
            return defined('APP_KEY') ? APP_KEY : __NAMESPACE__ . '-secure-secret-key';
        }

        /**
         * Returns the reconstructed closure.
         *
         * @return \Closure The closure instance
         */
        public function getClosure(): \Closure
        {
            return $this->closure;
        }
    }
}
