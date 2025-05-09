# POST MIGRATION

## 概要
このリポジトリはPost MigrationというWordpressのプラグインのソースコードを含んでいます。
zipファイルをダウンロードしてWordpress管理画面からプラグインのインストールを行うとプラグインとして機能します。
このプラグインは、WordPressの個別の投稿データを別のWordPressサイトに移行するという機能を提供します。
具体的には、WordPressが標準で提供するツールのインポート、エクスポートでは、提供されていない次の機能を提供します。
1. 標準ツールでは、画像等のメディアデータはエクスポートされません。そのかわり、インポーターは、XML内の画像URLを読み取り、リモートからファイルを再ダウンロードしようとします。しかし、これはインポート元のサイトが公開中で、メディアファイルのURLがアクセス可能であることが条件で、サイトがローカルだったり非公開だったりすると、メディアのダウンロードに失敗して、空の添付投稿だけが作成されることになります。
本プラグインは、画像等のメディアデータとWordPressのデータベース情報を抜き出して、一つのZIPファイルにまとめてエクスポートするため、そのような問題を解消します。
2. 標準ツールでは、リビジョンはエクスポートされませんが、本プラグインはエクスポート、インポート機能を提供します。
3. 標準ツールでは投稿タイプごとにエクスポートの対象を選択することはできますが、個別の投稿ごとに選択することはできません。
本プラグインは、個別の投稿ごとにエクスポートの対象を選択することができます。


## 留意事項
本プラグインはZIPファイルの生成、解凍のため、JSZipを使用しています。次のドキュメントを順守するようお願いします。
https://raw.github.com/Stuk/jszip/main/LICENSE.markdown
