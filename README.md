[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-stripe-reconciler?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-stripe-reconciler)
[![Static Badge](https://img.shields.io/badge/BUY-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/stripe-reconciler?craft5)

![Bundle Builder](https://johnhenry.ie/images/plugins/promos/stripe-reconciler/1.png)


# Stripe Reconciler for Craft Commerce

A customer pays by card, the money leaves their account, and then they close the tab before the browser makes it back to your site. Stripe has the payment. Craft Commerce knows nothing about it. The order sits there unpaid, or worse, it never became an order at all and is still sitting in the carts table where nobody will ever look at it.

That is the problem this plugin exists to solve. It finds those payments, checks them against Stripe, and finishes the job Commerce would have finished if the browser had come back.

## What it actually does

It looks for Stripe transactions that never reached a final state, asks Stripe what really happened to each one, and completes the ones that genuinely succeeded.

The important word there is "asks". Every decision comes from a live PaymentIntent fetched from Stripe at the moment the command runs. Nothing is inferred from what is sitting in your database. An order cannot be marked paid unless Stripe confirms the money arrived, and if Stripe says the payment was never made, the plugin says so and leaves your data alone.

The rule it works to is a simple one: do exactly what Commerce would have done if the customer's browser had returned to the redirect URL, and nothing more.

## Documentation

Full documentation is at [https://johnhenry.ie/plugins/stripe-reconciler/](https://johnhenry.ie/plugins/stripe-reconciler/docs)

## Requirements

- Craft CMS 5
- Craft Commerce 5
- Craft Commerce Stripe 5

## Support

For support, drop by the [GitHub Issues page](https://github.com/john-henry/craft-stripe-reconciler/issues).

## License

Proprietary - Copyright (c) 2026 John Henry Donovan

---

<a href="https://johnhenry.ie/plugins/" target="_blank">
    <img height="46" src="https://johnhenry.ie/images/plugins/logo.svg" alt="John Henry - Craft CMS Plugins">
</a>
