let isNavigatingWithinPlugin = false; // 「前へ」「次へ」ボタンでの遷移かどうかを判定
const storageKey = "itmar_selected_posts";
//jQuery.post を async/await に対応させる（ラップ関数を作る）
function postAsync(url, data) {
  return new Promise((resolve, reject) => {
    jQuery
      .post(url, data, function (response) {
        if (response.success) {
          resolve(response);
        } else {
          reject(response);
        }
      })
      .fail((jqXHR, textStatus, errorThrown) => {
        reject({
          jqXHR,
          textStatus,
          errorThrown,
        });
      });
  });
}

document.addEventListener("DOMContentLoaded", function () {
  //エクスポート処理の開始
  const form = document.getElementById("exportForm");

  if (form) {
    form.addEventListener("submit", async function (event) {
      event.preventDefault(); // ページリロードを止める
      ProgressOverlay.show(); // オーバーレイを表示
      ProgressOverlay.showChange();

      const formData = jQuery(this).serializeArray(); // ← export_posts[] 含む
      formData.push({
        name: "nonce",
        value: itmar_vars.nonce,
      });

      // Step1: selectedPosts を取得
      const getIdsPrm = [
        ...formData,
        {
          name: "action",
          value: "itmar_export_ids",
        },
      ];
      const idsResponse = await postAsync(itmar_vars.ajaxurl, getIdsPrm);
      const selectedPosts = idsResponse.data.selected_posts;
      const total = selectedPosts.length;

      // エクスポート先のzipを定義
      const zip = new JSZip();
      const allPostsData = []; // 投稿データを格納
      const mediaUrlSet = new Set(); // ← ここにURLを蓄積

      for (let index = 0; index < total; index++) {
        const post_id = selectedPosts[index];

        // Step2: 個別IDを使って export_json を送信
        const exportPrm = [
          ...formData,
          {
            name: "action",
            value: "itmar_export_json",
          },
          {
            name: "post_id",
            value: post_id,
          },
        ];

        try {
          //サーバーからデータ取得
          const response = await postAsync(itmar_vars.ajaxurl, exportPrm);
          ProgressOverlay.changeProgress(total, index + 1);

          if (response.success) {
            // 1件分の投稿データを配列に集積
            const postJson = response.data.json;
            allPostsData.push(postJson);
            //メディアのURLを集積
            const mediaUrls = response.data.media_urls || []; // 各投稿が返すメディアURL配列
            mediaUrls.forEach((url) => mediaUrlSet.add(url)); // ← Set に追加（重複無視）
          }
        } catch (error) {
          console.warn("Export failed for post ID:", post_id, error);
          // 失敗時も進めるならここでcontinue相当
          ProgressOverlay.changeProgress(total, index + 1);
        }
      }

      // JSON配列として1ファイルにまとめてZIPに追加
      const jsonString = JSON.stringify(allPostsData, null, 2); // JSON配列形式に整形
      zip.file("export_data.json", jsonString);

      // すべての投稿の処理が終わったあとにメディア一括処理
      const media_total = mediaUrlSet.size;
      let media_count = 0;

      for (const mediaUrl of mediaUrlSet) {
        ProgressOverlay.changeProgress(media_total, media_count + 1);
        if (mediaUrl) {
          const filename = mediaUrl.split("/").pop();
          try {
            const blob = await fetch(mediaUrl).then((res) => res.blob());
            zip.file(`exported_media/${filename}`, blob);
          } catch (err) {
            console.warn(`Failed to fetch media: ${mediaUrl}`, err);
          }
        }
        media_count++;
      }

      // ZIPファイルを生成して保存
      zip
        .generateAsync({
          type: "blob",
        })
        .then((content) => {
          saveAs(content, "exported_data.zip"); // ZIPファイルをダウンロード
          ProgressOverlay.hide();
        });
    });
  }

  let selectedPosts = []; //選択されたセレクトボックスをためる配列
  //実行ボタンのアニメーション関数
  const exec_animation = () => {
    const exec_button = document.querySelector(".footer_exec");
    if (exec_button) {
      if (selectedPosts.length > 0) {
        setTimeout(() => {
          exec_button.classList.add("appear");
        }, 100); // 100ms後にクラスを追加
      } else {
        setTimeout(() => {
          exec_button.classList.remove("appear");
        }, 100); // 100ms後にクラスを削除
      }
    }
  };

  //行見出しのチェックボックスを押したときに、そのテーブル内のチェックボックスが変更される処理
  document
    .querySelectorAll("input[id^='select-all-']")
    .forEach(function (checkbox) {
      checkbox.addEventListener("change", function () {
        let table = this.closest("table");
        table
          .querySelectorAll("input[name='export_posts[]']")
          .forEach(function (cb) {
            cb.checked = checkbox.checked;
            // **change イベントを手動で発生させる**
            cb.dispatchEvent(
              new Event("change", {
                bubbles: true,
              })
            );
          });
      });
    });

  function restoreSelectedPosts() {
    selectedPosts = JSON.parse(sessionStorage.getItem(storageKey)) || [];

    // チェックボックスの状態を復元
    document
      .querySelectorAll("input[name='export_posts[]']")
      .forEach(function (checkbox) {
        if (selectedPosts.includes(checkbox.value)) {
          checkbox.checked = true;
        }
      });
    document
      .querySelectorAll("input[name='export_types[]']")
      .forEach(function (checkbox) {
        if (selectedPosts.includes(checkbox.value)) {
          checkbox.checked = true;
        }
      });
    //選択済みの投稿IDをinput-hiddenに確保
    if (
      selectedPosts &&
      document.querySelector("input[name='all_export_posts']")
    ) {
      document.querySelector("input[name='all_export_posts']").value =
        selectedPosts.join(",");
    }

    //実行ボタンのアニメーション
    exec_animation();
  }

  // イベントリスナーを設定（チェックボックスが変更されたら配列を更新）
  document
    .querySelectorAll(
      "input[name='export_posts[]'], input[name='export_types[]']"
    )
    .forEach(function (checkbox) {
      checkbox.addEventListener("change", function () {
        if (this.checked) {
          // 選択された場合、配列に追加（重複を防ぐ）
          if (!selectedPosts.includes(this.value)) {
            selectedPosts.push(this.value);
          }
        } else {
          // 解除された場合、配列から削除
          selectedPosts = selectedPosts.filter((id) => id !== this.value);
        }
        //選択済みの投稿IDをinput-hiddenに確保
        if (selectedPosts) {
          document.querySelector("input[name='all_export_posts']").value =
            selectedPosts.join(",");
        }
        //実行ボタンのアニメーション
        exec_animation();
      });
    });

  // 「前へ」「次へ」のリンクがクリックされたときにデータを保存
  document.querySelectorAll(".tablenav-pages a").forEach(function (link) {
    link.addEventListener("click", function (event) {
      isNavigatingWithinPlugin = true; // フラグを立てる
      // **フォームを使用不可にする**
      document.querySelector("form").style.pointerEvents = "none";
      document.querySelector("form").style.opacity = "0.5";

      // **ローディング画像を表示**
      ProgressOverlay.show(); // "オーバーレイを表示"

      sessionStorage.setItem(storageKey, JSON.stringify(selectedPosts)); // クリック時にデータを保存
    });
  });

  // ページ読み込み時にデータを復元
  restoreSelectedPosts();

  // ZIP 内のファイルを保存するグローバル変数
  let zipFiles = {};
  //ZIPファイルのリーダー
  if (document.getElementById("inportForm")) {
    document
      .getElementById("inportForm")
      .addEventListener("submit", async function (event) {
        event.preventDefault();

        // **オーバーレイを表示**
        await ProgressOverlay.show(
          wp.i18n.__("Parsing import file...", "post-migration")
        );

        // `inport_result` を取得
        const inportResult = document.querySelector(".inport_result");
        const tbody = document.querySelector(".post_trns_tbody");
        // **開始時に `inport_result` を表示 & tbody を空にする**
        inportResult.style.display = "block"; // 表示
        tbody.innerHTML = ""; // tbody の内容をリセット
        //インポートモード
        let import_mode = document.querySelector(
          'input[name="import_mode" ]:checked'
        ).value;
        //ファイル名
        let fileInput = document.getElementById("import_file");
        if (fileInput.files.length === 0) {
          alert(wp.i18n.__("Select the ZIP file.", "post-migration"));
          ProgressOverlay.cancel();
          return;
        }
        let file = fileInput.files[0];

        const zip = new JSZip();
        const zipData = await file.arrayBuffer(); // ZIPデータを取得
        const unzipped = await zip.loadAsync(zipData); // ZIP解凍
        zipFiles = unzipped.files; //グローバル変数に渡す
        // "export_data.json" を探す
        const jsonFile = unzipped.file("export_data.json");
        if (!jsonFile) {
          alert(wp.i18n.__("export_data.json not found.", "post-migration"));
          ProgressOverlay.cancel();
          return;
        }
        const jsonText = await jsonFile.async("text");
        // JSONデータを解析
        const jsonDataArray = JSON.parse(jsonText);
        //本体データとリビジョンデータをまとめてjson配列を再構成
        const groupedData = [];
        let tempGroup = [];

        for (let i = 0; i < jsonDataArray.length; i++) {
          const item = jsonDataArray[i];

          // 最初の1件を追加
          if (tempGroup.length === 0) {
            tempGroup.push(item);
            continue;
          }

          // post_typeが"revision"なら現在のグループに追加
          if (item.post_type === "revision") {
            tempGroup.push(item);
          } else {
            // それ以外なら、現在のグループを保存して新しいグループを作成
            groupedData.push([...tempGroup]);
            tempGroup = [item]; // 新しいグループの開始
          }
        }

        // 最後のグループを追加
        if (tempGroup.length > 0) {
          groupedData.push([...tempGroup]);
        }

        // **結果ログを格納する配列**
        const result_log = [];

        const totalItems = groupedData.length; // **合計アイテム数**
        let processedItems = 0; // **処理済みカウント**

        //最初の１件が終了してからプログレスの形状を変えるためのフラグ
        let first_flg = true;

        //jsonDataを順次サーバーに送る
        for (const jsonData of groupedData) {
          //メディアファイルの収集
          const mediaData = [];

          for (const postData of jsonData) {
            //サムネイルのメディアファイルデータを取得
            if (postData.thumbnail_path) {
              const file = await extractMediaFile(postData.thumbnail_path);

              // すでに同じファイル名が存在するかをチェック
              const isDuplicate = mediaData.some(
                (existingFile) => existingFile.name === file.name
              );
              if (!isDuplicate) {
                mediaData.push(file);
              }
            }
            //投稿本文内のメディアファイルデータを取得
            const content_medias = [];
            if (postData.content) {
              //投稿本文からファイルのpathを取得
              const regex = /exported_media\/(.+?\.[a-zA-Z0-9]+)/gu; // "g" (global) と "u" (Unicode)
              const matches = [...postData.content.matchAll(regex)]; // すべての一致を取得

              // matches[0] 相当の結果を取得（完全一致した部分を取得）
              const contentMediaPaths = matches.map((match) => match[0]);
              //メディアデータを取得
              for (const media_path of contentMediaPaths) {
                if (media_path) {
                  const file = await extractMediaFile(media_path);
                  if (file !== null) {
                    // すでに同じファイル名が存在するかをチェック
                    const isDuplicate = mediaData.some(
                      (existingFile) => existingFile.name === file.name
                    );
                    if (!isDuplicate) {
                      mediaData.push(file);
                    }
                  } else {
                    console.error("Not exist:", media_path);
                  }
                }
              }
            }
            //acfメディアデータの取得
            const acf_medias = [];
            const regex = /exported_media\/(.+?\.[a-zA-Z0-9]+)/u; // "u" (Unicode)

            if (postData.acf_fields) {
              // Object.entries() を使って key-value をループ
              for (const [key, value] of Object.entries(postData.acf_fields)) {
                if (regex.test(value)) {
                  // 正規表現でマッチするかチェック
                  const file = await extractMediaFile(value);
                  // すでに同じファイル名が存在するかをチェック
                  const isDuplicate = mediaData.some(
                    (existingFile) => existingFile.name === file.name
                  );
                  if (!isDuplicate) {
                    mediaData.push(file);
                  }
                }
              }
            }
          }

          try {
            const resultObj = await sendFetchData(
              jsonData,
              mediaData,
              import_mode
            );

            if (first_flg) {
              // **解析完了後の処理**
              ProgressOverlay.showChange();
              first_flg = false; //フラグをおろす
            }

            //プログレスバーの更新関数
            processedItems++;
            ProgressOverlay.changeProgress(totalItems, processedItems);

            // `result` からデータを取得 (サーバーのレスポンス構造に応じて修正)
            const { id, title, result, log, message } = resultObj;
            //キャンセルが検出されたら終了(ループから抜ける)
            if (result === "cancel") break;

            //テーブルに結果出力
            const line_class = result === "error" ? "skip_line" : "data_line";

            // `tr` 要素を作成
            const tr = document.createElement("tr");
            tr.classList.add(line_class); // クラスを追加

            tr.innerHTML = `
                   <td>${id}</td>
                   <td>${title}</td>
                   <td>${result}</td>
                   <td>${message}</td>
               `;

            // テーブルに追加
            tbody.appendChild(tr);
            //ログの集積
            log.push(""); // 空白行
            result_log.push(...log);
          } catch (error) {
            console.error("送信エラー:", error);
          }
        }

        // **完了時にオーバーレイを非表示**
        ProgressOverlay.hide();

        // エラーログがある場合、ファイルを作成してダウンロード
        if (result_log.length != 0) {
          // **ログファイルを作成して保存**
          createLogFile(result_log);
        }
      });
  }

  /**
   * result_log を HTML 文書として生成し、ファイルを保存
   */
  function createLogFile(result_log) {
    // HTML ドキュメントの作成
    let logHtml = `<!DOCTYPE html>
             <html lang="ja">
             <head>
                 <meta charset="UTF-8">
                 <title>Import Log</title>
             </head>
             <body>
                 <h2>Import Log</h2>
                 <pre>${result_log.join("\n")}</pre>
             </body>
             </html>`;

    // Blob 生成
    const blob = new Blob([logHtml], {
      type: "text/html",
    });
    const url = URL.createObjectURL(blob);

    // **ダウンロードリンクの作成**
    let logLink = document.createElement("a");
    logLink.href = url;
    logLink.download = "import_log.html";
    logLink.textContent = wp.i18n.__(
      "Download the import log",
      "post-migration"
    );
    logLink.style.display = "block";
    logLink.style.marginTop = "10px";

    // `inport_result` の後に挿入
    document.querySelector(".inport_result").after(logLink);
  }

  async function extractMediaFile(mediaPath) {
    if (!zipFiles || Object.keys(zipFiles).length === 0) {
      return null;
    }
    //ファイルの存在確認
    const matchingFile = Object.keys(zipFiles).find((fileName) =>
      fileName.includes(mediaPath)
    );
    if (!matchingFile) {
      return null;
    }
    // ファイルのバイナリデータを取得
    const fileData = await zipFiles[matchingFile].async("arraybuffer");

    const file = new File([fileData], matchingFile, {
      type: "application/octet-stream",
    });
    // ✅ `File` オブジェクトに `mediaPath` を追加
    return file;
  }

  async function sendFetchData(postData, mediaData, import_mode) {
    const formData = new FormData();
    formData.append("action", "post_data_fetch");
    formData.append("nonce", itmar_vars.nonce);
    formData.append("post_data", JSON.stringify(postData)); // JSON化して送信
    formData.append("import_mode", import_mode);
    // ✅ mediaData の各ファイルを FormData に追加
    mediaData.forEach((file, index) => {
      formData.append(`media_files[${index}]`, file);
    });

    // サーバーに送信
    try {
      const response = await fetch(itmar_vars.ajaxurl, {
        method: "POST",
        body: formData,
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const data = await response.json(); // ✅ **PHP からの戻り値を受け取る**

      return data;
    } catch (error) {
      console.error("Fetch error:", error);
      return data;
    }
  }
});

// **このページから離脱するときに `sessionStorage` をクリア**
window.addEventListener("beforeunload", function () {
  if (
    window.location.search.includes("page=itmar_post_tranfer_export") &&
    !isNavigatingWithinPlugin
  ) {
    sessionStorage.removeItem("itmar_selected_posts");
  }
});
