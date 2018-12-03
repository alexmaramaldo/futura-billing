<?php namespace Souldigital\Billing\Exceptions;

class ValidationException extends \Exception
{
    protected $errors = [];

    /**
     * ValidationException constructor.
     *
     * @param int   $status
     * @param mixed $errors
     */
    public function __construct(\Illuminate\Contracts\Validation\Validator $validator = null, $message = "")
    {
        $info = 'Os seguintes erros de validação foram encontrados: ';
        if ($validator) {
            foreach ($validator->errors()->all() as $erro) {
                $info .= ' --'.$erro.' ';
            }
        } else {
            $info .= $message;
        }
        $this->errors = $validator->errors(0);

        parent::__construct($info);
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
