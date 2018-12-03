<?php

namespace Souldigital\Billing;

use Exception;
use Carbon\Carbon;
use Souldigital\Billing\Exceptions\ValidationException;

class Billing
{
    /**
     * The current currency.
     *
     * @var string
     */
    protected static $currency = 'brl';

    /**
     * The current currency symbol.
     *
     * @var string
     */
    protected static $currencySymbol = 'R$';

    /**
     * The custom currency formatter.
     *
     * @var callable
     */
    protected static $formatCurrencyUsing;

    /**
     * Set the currency to be used when billing Vindi models.
     *
     * @param  string      $currency
     * @param  string|null $symbol
     * @return void
     */
    public static function useCurrency($currency, $symbol = null)
    {
        static::$currency = $currency;

        static::useCurrencySymbol($symbol ?: static::guessCurrencySymbol($currency));
    }

    /**
     * Guess the currency symbol for the given currency.
     *
     * @param  string $currency
     * @return string
     */
    protected static function guessCurrencySymbol($currency)
    {
        switch (strtolower($currency)) {
            case 'usd':
            case 'aud':
            case 'cad':
                return '$';
            case 'eur':
                return '€';
            case 'gbp':
                return '£';
            default:
                throw new Exception('Unable to guess symbol for currency. Please explicitly specify it.');
        }
    }

    /**
     * Get the currency currently in use.
     *
     * @return string
     */
    public static function usesCurrency()
    {
        return static::$currency;
    }

    /**
     * Set the currency symbol to be used when formatting currency.
     *
     * @param  string $symbol
     * @return void
     */
    public static function useCurrencySymbol($symbol)
    {
        static::$currencySymbol = $symbol;
    }

    /**
     * Get the currency symbol currently in use.
     *
     * @return string
     */
    public static function usesCurrencySymbol()
    {
        return static::$currencySymbol;
    }

    /**
     * Set the custom currency formatter.
     *
     * @param  callable $callback
     * @return void
     */
    public static function formatCurrencyUsing(callable $callback)
    {
        static::$formatCurrencyUsing = $callback;
    }

    /**
     * Format the given amount into a displayable currency.
     *
     * @param  int $amount
     * @return string
     */
    public static function formatAmount($amount)
    {
        if (static::$formatCurrencyUsing) {
            return call_user_func(static::$formatCurrencyUsing, $amount);
        }

        $amount = number_format($amount / 100, 2);

        if (starts_with($amount, '-')) {
            return '-'.static::usesCurrencySymbol().ltrim($amount, '-');
        }

        return static::usesCurrencySymbol().$amount;
    }

    /**
     * Valida os dados de pagamento informados. Caso haja erros de validação lança uma exception.
     *
     * @param array $paymentData
     */
    public static function validatePaymentData(array $paymentData)
    {

        $dataAtual = Carbon::now();
        $mesAtual = $dataAtual->month;
        $anoAtual = $dataAtual->year;

        $validator = \Validator::make(
            $paymentData,
            [
            'payment_type'          => 'required',
            'holder_name'           => 'required_if:payment_type,credit_card',
            'card_expiration_month' => 'required_if:payment_type,credit_card|digits:2|numeric|min:01|max:12',
            'card_expiration_year'  => 'required_if:payment_type,credit_card|digits:4|numeric|min:'.$anoAtual,
            'card_number'           => 'required_if:payment_type,credit_card|digits:16|numeric',
            'card_cvv'              => 'required_if:payment_type,credit_card'
            ]
        );

        $validator->sometimes(
            'card_expiration_month',
            'min:'.$mesAtual,
            function ($paymentData) use ($anoAtual) {
                return $paymentData['card_expiration_year'] == $anoAtual;
            }
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $paymentData;
    }

    public static function validateCpf($cpf)
    {
        // Verifica se um número foi informado
        if (empty($cpf)) {
            throw new ValidationException(null, "--É preciso informar um valor para o CPF.");
        }

        // Elimina possivel mascara
        $cpf = preg_replace('[^0-9]', '', $cpf);
        $cpf = str_replace('.', '', $cpf);
        $cpf = str_replace('-', '', $cpf);
        $cpf = str_pad($cpf, 11, '0', STR_PAD_LEFT);

        // Verifica se o numero de digitos informados é igual a 11
        if (strlen($cpf) != 11) {
            throw new ValidationException(null, "--O CPF precisa ter 11 digitos.");
        }
        // Verifica se nenhuma das sequências invalidas abaixo
        // foi digitada. Caso afirmativo, retorna falso
        else if ($cpf == '00000000000' ||
            $cpf == '11111111111' ||
            $cpf == '22222222222' ||
            $cpf == '33333333333' ||
            $cpf == '44444444444' ||
            $cpf == '55555555555' ||
            $cpf == '66666666666' ||
            $cpf == '77777777777' ||
            $cpf == '88888888888' ||
            $cpf == '99999999999') {
            throw new ValidationException(null, "--O CPF informado é inválido.");
            // Calcula os digitos verificadores para verificar se o
            // CPF é válido
        } else {

            for ($t = 9; $t < 11; $t++) {

                for ($d = 0, $c = 0; $c < $t; $c++) {
                    $d += $cpf{$c} * (($t + 1) - $c);
                }
                $d = ((10 * $d) % 11) % 10;
                if ($cpf{$c} != $d) {
                    throw new ValidationException(null, "--O CPF informado é inválido.");
                }
            }

            return $cpf;
        }
    }
}
