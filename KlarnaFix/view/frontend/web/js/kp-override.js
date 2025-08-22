define(['mage/url'], function (urlBuilder) {
  'use strict';
  return function (Component) {
    return Component.extend({
      afterPlaceOrder: function () {
        if (window.__kpAfterPlaceOrderFired) return true;
        window.__kpAfterPlaceOrderFired = true;

        var successUrl = urlBuilder.build('checkout/onepage/success/');
        try { window.stop && window.stop(); } catch (e) {}
        window.location.replace(successUrl);
        return true;
      }
    });
  };
});
