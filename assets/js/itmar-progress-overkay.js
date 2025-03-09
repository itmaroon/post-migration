window.ProgressOverlay = (function () {
  return {
    show: function (message = "処理中...") {
      document.getElementById("importOverlay").style.display = "block";
      document.getElementById("progressText").textContent = message;
      document.getElementById("progressBarWrapper").style.display = "none";
      document.getElementById("progressLoadingImg").style.display = "block";
    },

    showProgress: function (total, current) {
      document.getElementById("progressLoadingImg").style.display = "none";
      document.getElementById("progressBarWrapper").style.display = "block";
      document.getElementById(
        "progressText"
      ).textContent = `全 ${total} 件中 ${current} 件目処理中...`;
      document.getElementById("progressBar").style.width =
        (current / total) * 100 + "%";
    },

    hide: function () {
      setTimeout(() => {
        document.getElementById("importOverlay").style.display = "none";
      }, 1000);
    },
  };
})();
