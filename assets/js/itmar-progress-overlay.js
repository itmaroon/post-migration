window.ProgressOverlay = (function () {
  return {
    show: function (message) {
      document.getElementById("importOverlay").style.display = "block";
      document.getElementById("progressText").textContent = message;
      document.getElementById("progressBarWrapper").style.display = "none";
      document.getElementById("progressLoadingImg").style.display = "block";
    },

    showChange: function () {
      document.getElementById("progressLoadingImg").style.display = "none";
      document.getElementById("progressBarWrapper").style.display = "block";
    },

    changeProgress: function (total, current, allcount = 0, count = 0) {
      document.getElementById("progressText").textContent = `${wp.i18n.__(
        "All",
        "post-migration"
      )} ${total} ${wp.i18n.__(
        "Items",
        "post-migration"
      )}  ${current} ${wp.i18n.__("Processing...", "post-migration")}`;

      if (allcount === 0 || count === 0) {
        document.getElementById("progressBar").style.width =
          (current / total) * 100 + "%";
      } else {
        document.getElementById("progressBar").style.width =
          (count / allcount) * 100 + "%";
      }
    },

    hide: function () {
      setTimeout(() => {
        document.getElementById("importOverlay").style.display = "none";
      }, 1000);
    },
  };
})();
