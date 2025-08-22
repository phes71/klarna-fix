define([], function () {
  'use strict';
  return function (original) {
    return function () {
      // intentionally do nothing — we own the redirect now
      return;
    };
  };
});
