var config = {
  config: {
    mixins: {
      // Take over Klarna’s component afterPlaceOrder and perform a single, direct success redirect.
      'Klarna_Kp/js/view/payments/kp': {
        'GerrardSBS_KlarnaFix/js/kp-override': true
      }
    }
  }
};
