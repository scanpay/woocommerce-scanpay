!function(){"use strict";var e=window.wp.element,a=window.wc.wcBlocksRegistry;const t=(0,window.wc.wcSettings.getSetting)("paymentMethodData");if(!t.scanpay)throw"Scanpay settings not found";(0,a.registerPaymentMethod)({name:"scanpay",label:(0,e.createElement)(e.Fragment,null,(0,e.createElement)("span",{className:"wcsp-blocks-title"},"Betalingskort"),(0,e.createElement)("span",{className:"wcsp-blocks-cards"},["dankort","visa","mastercard"].map((a=>(0,e.createElement)("img",{width:"",className:"wcsp-blocks-ico",src:t.scanpay.url+a+".svg"}))))),content:(0,e.createElement)(e.Fragment,null,t.scanpay.description),edit:(0,e.createElement)(e.Fragment,null,t.scanpay.description),canMakePayment:()=>!0,ariaLabel:t.scanpay.title,supports:{features:["products","subscriptions"]}}),(0,a.registerPaymentMethod)({name:"scanpay_mobilepay",label:(0,e.createElement)(e.Fragment,null,(0,e.createElement)("span",{className:"wcsp-blocks-title"},"MobilePay"),(0,e.createElement)("img",{width:"94",height:"24",src:t.scanpay.url+"mobilepay.svg"})),content:(0,e.createElement)(e.Fragment,null,"Betal med MobilePay"),edit:(0,e.createElement)(e.Fragment,null,"Betal med MobilePay"),canMakePayment:()=>!0,ariaLabel:"MobilePay",supports:{features:["products"]}}),(0,a.registerPaymentMethod)({name:"scanpay_applepay",label:(0,e.createElement)(e.Fragment,null,(0,e.createElement)("span",{className:"wcsp-blocks-title"},"Apple Pay"),(0,e.createElement)("img",{width:"50",height:"22",src:t.scanpay.url+"applepay.svg"})),content:(0,e.createElement)(e.Fragment,null,"Betal med Apple Pay"),edit:(0,e.createElement)(e.Fragment,null,"Betal med Apple Pay"),canMakePayment:()=>!0,ariaLabel:"Apple Pay",supports:{features:["products"]}})}();