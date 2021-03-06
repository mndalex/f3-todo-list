<?php

class Validator
{
    private $errors = [];

    private $attributes;

    private $attributeRules;

    private $f3;

    /**
     * Attribute name casts for error messages
     *
     * @var array
     */
    private $attributeCasts = [];

    public function __construct(array $attributes, array $attributeRules)
    {
        $this->attributes = $attributes;

        $this->attributeRules = $attributeRules;

        $this->f3 = Base::instance();
    }

    public function validate(): bool
    {
        foreach ($this->attributeRules as $attributeName => $rules) {
            usort($rules, function ($a, $b) {
                if ($b === 'nullable') {
                    return 1;
                }
                return -1;
            });

            foreach ($rules as $rule) {
                [$rule, $arguments] = explode(':', $rule, 2);

                $arguments = explode(',', $arguments);

                $functionName = "validate" . ucfirst($rule);

                if (!method_exists('Validator', $functionName)) {
                    $this->f3->error(422, "Invalid validation rule: $rule");
                } else {
                    if (!call_user_func([$this, $functionName], $attributeName, $arguments)) {
                        break;
                    }
                }
            }
        }

        return empty($this->errors());
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Set the attribute casts
     * 
     * @param array $casts
     * @return void 
     */
    public function setAttributeCasts(array $casts): void
    {
        $this->attributeCasts = $casts;
    }

    private function sanitizeInputs(array $attributes): array
    {
        $sanitizedInputs = [];

        foreach ($attributes as $attribute) {
            $sanitizedInputs[] = trim(htmlspecialchars($attribute));
        }

        return $sanitizedInputs;
    }

    private function validateNullable(string $attributeName): bool
    {
        return $this->attributes[$attributeName] != null;
    }

    private function validateRequired(string $attributeName): bool
    {
        $attribute = $this->attributes[$attributeName];

        if (!is_null($attribute) && $attribute !== '') {
            return true;
        }

        $this->errors[$attributeName] = "The " . $this->determineAttributeNameForErrorMessage($attributeName) . " field is required!";

        return false;
    }

    private function validateMin(string $attributeName, array $arguments): bool
    {
        $min = $arguments[0];

        $attribute = $this->attributes[$attributeName];

        if (strlen($attribute) >= $min) {
            return true;
        }

        $this->errors[$attributeName] = "The " . $this->determineAttributeNameForErrorMessage($attributeName) . " is too short (min length: $min)!";

        return false;
    }

    private function validateMax(string $attributeName, array $arguments): bool
    {
        $max = $arguments[0];

        $attribute = $this->attributes[$attributeName];

        if (strlen($attribute) <= $max) {
            return true;
        }

        $this->errors[$attributeName] = "The " . $this->determineAttributeNameForErrorMessage($attributeName) . " is too long (max length: $max)!";

        return false;
    }

    private function validateConfirmed(string $attributeName, array $arguments): bool
    {
        $confirmationInputName = $arguments[0];

        echo $confirmationInputName;

        if (!$confirmationInputName) {
            $confirmationInputName = $attributeName . "_confirmation";
        }

        if ($this->attributes[$attributeName] === $this->attributes[$confirmationInputName]) {
            return true;
        }

        $this->errors[$attributeName] = "The " . $this->determineAttributeNameForErrorMessage($attributeName) . " must be confirmed!";

        return false;
    }

    private function validateUnique(string $attributeName, array $arguments): bool
    {
        $table = $arguments[0];
        $column = $arguments[1];

        if (!$column) {
            $column = $attributeName;
        }

        $mapper = createSQLMapper($table);

        checkIfColumnExistsInMapper($mapper, $column);

        $mapper->load([
            "$column=?", $this->attributes[$attributeName]
        ]);

        if (!$mapper->id) {
            return true;
        }

        $this->errors[$attributeName] = "The " . $this->determineAttributeNameForErrorMessage($attributeName) . " must be unique!";

        return false;
    }

    /**
     * Validate the $this->arguments[$attributeName] format.
     * 
     * @param string $attributeName 
     * @param array $arguments 
     * @return bool 
     */
    private function validateFormat(string $attributeName, array $arguments): bool
    {
        $regexp = "/" . $arguments[0] . "/";

        if (!$regexp) {
            $this->f3->error(422, "Too few arguments passed! Regular expression argument required!");
        }

        if (preg_match($regexp, $this->attributes[$attributeName])) {
            return true;
        }

        $this->errors[$attributeName] = "The " . $this->determineAttributeNameForErrorMessage($attributeName) . " must be match the regexp pattern: $arguments[0]";

        return false;
    }

    /**
     * Return true if the attribute is exist in the database, otherwise return false.
     * 
     * @param string $attributeName 
     * @param array $arguments 
     * @return bool 
     */
    private function validateExists(string $attributeName, array $arguments): bool
    {
        $table = $arguments[0];

        $column = $arguments[1] ?: $attributeName;

        $mapper = createSQLMapper($table);

        checkIfColumnExistsInMapper($mapper, $column);

        $mapper->load([
            "$column=?",
            $this->attributes[$attributeName]
        ]);

        if (!$mapper->dry()) {
            return true;
        }

        $this->errors[$attributeName] = "This $column (" . $this->attributes[$attributeName] . ") dosn't exist in the $table table";

        return false;
    }

    private function determineAttributeNameForErrorMessage($attributeName)
    {
        return $this->attributeCasts[$attributeName] ?: $attributeName;
    }
}
