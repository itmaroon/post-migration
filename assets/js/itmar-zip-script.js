document.addEventListener("DOMContentLoaded", function () {
  const fileInput = document.getElementById("zipFileInput");
  const fileListDisplay = document.getElementById("fileList");

  if (!fileInput || !fileListDisplay) {
    console.error("Required elements not found.");
    return;
  }

  fileInput.addEventListener("change", async function (event) {
    const file = event.target.files[0];

    if (!file) {
      console.error("No file selected.");
      return;
    }

    const zip = new JSZip();

    try {
      const zipData = await zip.loadAsync(file);
      let fileList = Object.keys(zipData.files);

      // ZIP 内のファイルリストを表示
      fileListDisplay.innerHTML =
        "<h4>ZIP 内のファイル:</h4><ul>" +
        fileList.map((name) => `<li>${name}</li>`).join("") +
        "</ul>";

      // 必要な情報だけサーバーに送信
      fetch(my_plugin_ajax.ajax_url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          action: "process_zip",
          file_list: fileList,
        }),
      })
        .then((response) => response.json())
        .then((data) => {
          alert("Server Response: " + data.message);
        })
        .catch((error) => console.error("Error:", error));
    } catch (error) {
      console.error("Error extracting ZIP:", error);
    }
  });
});
