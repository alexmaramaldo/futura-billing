# Soul Digital Billing

## Introduction

Soul Digital Billing provides an expressive, fluent interface to [Vindi's](https://vindi.com.br) subscription billing services. It handles almost all of the boilerplate subscription billing code you are dreading writing.
## Test Setup

You will need to set the following details locally and on your Vindi account in order to run the Billing unit tests:

### Local

#### .env

    BILLING_MODEL=
    VINDI_API_KEY=
    CREDIT_CARD_LABEL=

### Vindi

#### Plans

    * mensal-teste ($10)
    * mensal-teste-2 ($10)