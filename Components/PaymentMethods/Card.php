<?php
// Copyright (c) Pickware GmbH. All rights reserved.
// This file is part of software that is released under a proprietary license.
// You must not copy, modify, distribute, make publicly available, or execute
// its contents or parts thereof without express permission by the copyright
// holder, unless otherwise permitted by law.

namespace Shopware\Plugins\StripePayment\Components\PaymentMethods;

use Shopware\Plugins\StripePayment\Util;
use Stripe;

class Card extends AbstractStripePaymentIntentPaymentMethod
{
    /**
     * @inheritdoc
     */
    public function createStripePaymentIntent($amountInCents, $currencyCode)
    {
        Util::initStripeAPI();

        // Determine the card
        $stripeSession = Util::getStripeSession();
        if (!$stripeSession->selectedCard || !isset($stripeSession->selectedCard['id'])) {
            throw new \Exception($this->getSnippet('payment_error/message/no_card_selected'));
        }

        $stripeCustomer = Util::getStripeCustomer();
        if (!$stripeCustomer) {
            $stripeCustomer = Util::createStripeCustomer();
        }
        $user = $this->get('session')->sOrderVariables['sUserData'];
        $userEmail = $user['additional']['user']['email'];
        $customerNumber = $user['additional']['user']['customernumber'];

        // Use the token to create a new Stripe payment intent
        $paymentIntentConfig = [
            'amount' => $amountInCents,
            'currency' => $currencyCode,
            'payment_method' => $stripeSession->selectedCard['id'],
            'confirmation_method' => 'automatic',
            'confirm' => true,
            'return_url' => $this->assembleShopwareUrl([
                'controller' => 'StripePaymentIntent',
                'action' => 'completeRedirectFlow',
            ]),
            'metadata' => $this->getSourceMetadata(),
            'customer' => $stripeCustomer->id,
            'description' => sprintf('%s / Customer %s', $userEmail, $customerNumber),
        ];
        if ($this->includeStatementDescriptorInCharge()) {
            $paymentIntentConfig['statement_descriptor'] = mb_substr($this->getStatementDescriptor(), 0, 22);
        }

        // Enable MOTO transaction, if configured and order is placed by shop admin (aka user has logged in via backend)
        $pluginConfig = $this->get('plugins')->get('Frontend')->get('StripePayment')->Config();
        $isAdminRequest = isset($this->get('session')->Admin) && $this->get('session')->Admin === true;
        if ($isAdminRequest && $pluginConfig->get('allowMotoTransactions')) {
            $paymentIntentConfig['payment_method_options'] = [
                'card' => [
                    'moto' => true,
                ],
            ];
        }

        // Enable receipt emails, if configured
        if ($pluginConfig->get('sendStripeChargeEmails')) {
            $paymentIntentConfig['receipt_email'] = $userEmail;
        }

        if ($stripeSession->saveCardForFutureCheckouts) {
            // Add the card to the Stripe customer
            $paymentIntentConfig['save_payment_method'] = $stripeSession->saveCardForFutureCheckouts;
            unset($stripeSession->saveCardForFutureCheckouts);
        }

        $paymentIntent = Stripe\PaymentIntent::create($paymentIntentConfig);
        if (!$paymentIntent) {
            throw new \Exception($this->getSnippet('payment_error/message/transaction_not_found'));
        }

        return $paymentIntent;
    }

    /**
     * @inheritdoc
     */
    public function includeStatementDescriptorInCharge()
    {
        // Card payment methods can be reused several times and hence should contain a statement descriptor in charge
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getSnippet($name)
    {
        return ($this->get('snippets')->getNamespace('frontend/plugins/payment/stripe_payment/card')->get($name)) ?: parent::getSnippet($name);
    }

    /**
     * @inheritdoc
     */
    public function validate($paymentData)
    {
        // Check the payment data for a selected card
        if (empty($paymentData['selectedCard'])) {
            return [
                'STRIPE_CARD_VALIDATION_FAILED'
            ];
        }

        return [];
    }
}
