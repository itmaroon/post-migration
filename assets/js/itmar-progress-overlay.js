window.ProgressOverlay = (function () {
  //処理の開始と中断のためのフックに渡すフォームデータ

  return {
    show: async function (message) {
      document.getElementById("importOverlay").style.display = "block";
      document.getElementById("progressText").textContent = message;
      document.getElementById("progressBarWrapper").style.display = "none";
      document.getElementById("progressLoadingImg").style.display = "block";

      const formData = new URLSearchParams();
      formData.append("nonce", ajax_object.nonce);
      formData.append("action", "start_cancel_progress");
      formData.append("flg", "false"); // ✅ `"false"` に変更

      try {
        // ✅ `fetch()` を `await` することで、完了を待つ
        const response = await fetch(ajax_object.ajaxurl, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: formData,
        });

        const data = await response.json();
        console.log("started:", data);
      } catch (error) {
        console.error("Error:", error);
      }
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

    cancel: function () {
      const formData = new URLSearchParams();
      formData.append("nonce", ajax_object.nonce);
      formData.append("action", "start_cancel_progress");
      formData.append("flg", "true"); // ✅ `"true"` に変更

      fetch(ajaxurl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: formData,
      })
        .then((response) => response.json())
        .then((data) => {
          alert(wp.i18n.__(data.data.message, "post-migration"));
          this.hide();
        })
        .catch((error) => console.error("Error:", error));
    },
  };
})();

// **キャンセルボタンのクリックイベントを追加**
document.addEventListener("DOMContentLoaded", function () {
  document
    .getElementById("cancelButton")
    .addEventListener("click", function () {
      ProgressOverlay.cancel();
    });
});
