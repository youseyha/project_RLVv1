<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentGateWaysController extends Controller
{
    //List of supported payment gateways
    public function index()
    {
        $gateways = [
            [
                'name' => 'Stripe',
                'logo' => 'https://stripe.com/img/v3/home/twitter.png',
                'description' => 'Stripe is a technology company that builds economic infrastructure for the internet. Businesses of every size—from new startups to public companies—use our software to accept payments and manage their businesses online.',
                'website' => 'https://stripe.com',
            ],
            [
                'name' => 'PayPal',
                'logo' => 'https://www.paypalobjects.com/webstatic/icon/pp258 x258.png',
                'description' => 'PayPal is a global online payment system that supports online money transfers and serves as an electronic alternative to traditional paper methods like checks and money orders.',
                'website' => 'https://www.paypal.com',
            ],
            [
                'name' => 'Square',
                'logo' => 'https://squareup.com/favicon.ico',
                'description' => 'Square is a financial services and mobile payment company that provides a range of tools for businesses to accept payments, manage their operations, and grow their customer base.',
                'website' => 'https://squareup.com',
            ],
            [
                'name' => 'Authorize.Net',
                'logo' => 'https://www.authorize.net/content/dam/authorizenet/images/authorize-net-logo.png',
                'description' => 'Authorize.Net is a payment gateway service provider that allows merchants to accept credit card and electronic check payments through their website and over an IP connection.',
                'website' => 'https://www.authorize.net',
            ],
            [
                'name' => 'Braintree',
                'logo' => 'https://www.braintreepayments.com/static/img/braintree-logo.png',
                'description' => 'Braintree is a full-stack payment platform that makes it easy to accept payments in your app or website. It offers a range of features including support for multiple payment methods, fraud protection, and seamless integration.',
                'website' => 'https://www.braintreepayments.com',
            ],
        ]; 

        return response()->json($gateways);
    }     
}
