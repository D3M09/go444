(function (w, d) {
  var count = 0;
  var fileList = ["assets/resource/main.DJLGQFAz.css","assets/entry/main.Baglbadd.js"];

  function onload() {
    count += 1;
    if (count === fileList.length) {
      w.dispatchEvent(new CustomEvent("promo-ui-onload"));
    }
  }

  for (let i = 0; i < fileList.length; i++) {
    var isJS = /js/g.test(fileList[i]);
    var isCSS = /css/g.test(fileList[i]);
    var e;

    if (isJS) {
      var s = document.createElement("script");
      s.setAttribute("type", "module");
      s.src = w.location.origin + "/common/promo-ui/" + fileList[i];
      s.onload = onload;
      d.body.append(s);
    } else if (isCSS) {
      e = d.createElement("link");
      e.rel = "stylesheet";
      e.href = w.location.origin + "/common/promo-ui/" + fileList[i];
      e.onload = onload;
      d.head.appendChild(e);
    }
  }
})(window, document);
