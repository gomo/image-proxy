## ImageProxy

AWSのS3などに画像をおいた時、転送量を減らすために、別サーバーをキャッシュ用のリバースプロキシとして使うためのシステム。
画像アクセス時にサイズを指定してリサイズも可能です。WEBサーバーはapacheを想定しています。

### 設定方法

ドキュメントルート以下の任意のディレクトリに`index.php`、`config.php`、`.htaccess`を配置します。

画像キャッシュを保存するディレクトリを同じ階層に作成しapacheユーザーから書き込み出来るようにして下さい。
画像ディレクトリの名前は`config.php`内に指定します。

`doc-root/img`にシステムを配置する例を示します。画像ディレクトリは`files`、オリジナル画像は`http://bucket.s3.amazonaws.com/`でアクセス可能だと仮定します。

```
└── doc-root
    └── img
        ├── .htaccess
        ├── files
        ├── config.php
        └── index.php
```

`config.php`各値の意味はコメントを参照して下さい。

```php
$settings = array(
  //元画像のオリジナルサーバーの設定。キーにないドメインは404が返されます。
  'server' => array(
    'bucket.s3.amazonaws.com' => array(
      'ip' => '127.0.0.1',     //省略可能。省略時はDNSを利用する
      'protocol' => 'http',    //省略可能。省略時は`http`
      'inherit' => 'default',  //他の設定を継承できる
    ),
  ),

  //ファイル名からリサイズ情報を取り出す正規表現。$matches[1]が'width_var'か'height_var'。$matches[2]が値（数字）
  //拡大はしません。false（に評価される値）を渡すとリサイズしません。
  'size_regex' => '/^(w|h)([0-9]{1,2}0)_/u',
  'width_var' => 'w',
  'height_var' => 'h',

  //スクリプトファイル`index.php`の階層から相対パスで、ここに指定したディレクトリを検索し、そこに画像をキャッシュします。
  //apacheユーザーから書き込みができないとエラーになります。
  'img_dir' => 'files',
);
```

オリジナル画像が`http://bucket.s3.amazonaws.com/path/to/sample.jpg`でアクセス可能な場合、プロキシサーバーでは`/bucket.s3.amazonaws.com/img/files/path/to/sample.jpg`でアクセス可能です。`/bucket.s3.amazonaws.com/img/files/path/to/w120_sample.jpg`で幅120に縮小、`/bucket.s3.amazonaws.com/img/files/path/to/h80_sample.jpg`で高さ80に縮小します。

アクセスした画像は全てプロキシサーバーにキャッシュされ、キャッシュされた画像は二度とオリジナルサーバーへは取りに行きません。同じファイル名で別の画像に差し替えるような場合は注意が必要です（そのようなシステムは想定していません。画像毎にURLがユニークになるようなシステムを想定しています）。

### リサイズと依存関係

リサイズ機能を利用する場合[Image Magick](http://www.imagemagick.org/script/index.php)がサーバーにインストールしてある必要があります。画像形式は`jpeg` `png` `gif`で動作確認を行っています。


### キャッシュ画像の掃除

30日以上アクセスのなかった画像を削除するコマンドです。

```shell
find /path/to/doc-root/img/files -type f -atime +30 -delete
```
