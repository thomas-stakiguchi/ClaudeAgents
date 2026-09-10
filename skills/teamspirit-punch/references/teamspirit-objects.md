# TeamSpirit オブジェクトメモ（thomas株式会社 本番組織で 2026-09-10 に確認）

## 勤怠社員 teamspirit__AtkEmp__c

| 項目 | 意味 |
|---|---|
| Name | 社員名 |
| teamspirit__EmpCode__c | 社員コード（例 0028） |
| teamspirit__UserId__c | Salesforceユーザーへの参照。ログインユーザーと勤怠社員をつなぐ唯一の根拠 |
| teamspirit__EntryDate__c | 入社日 |
| teamspirit__EndDate__c | 退社日。空なら在籍中 |

## 勤怠日次 teamspirit__AtkEmpDay__c

勤怠社員への直接の参照は無い。`teamspirit__EmpMonthId__r.teamspirit__EmpId__c`（勤怠月次経由）でたどる。

| 項目 | 意味 |
|---|---|
| teamspirit__Date__c | 日付 |
| teamspirit__PushStartTime__c / PushEndTime__c | ボタン打刻の出社・退社（0:00からの分数） |
| teamspirit__RealStartTime__c / RealEndTime__c | 丸め前の打刻 |
| teamspirit__StartTime__c / EndTime__c | 勤務表上の出社・退社。EndTime は退社前でも埋まっていることがあるので退社判定に使わない |
| teamspirit__...HHMM__c | 上記の「HH:MM」文字列版 |

実例: PushStartTime 474 → 07:54。

## 勤怠連携 teamspirit__ExternalAttendance__c（打刻の受け口）

| 項目 | 意味 |
|---|---|
| teamspirit__EmpId__c | 勤怠社員への参照 |
| teamspirit__EmpCode__c / teamspirit__Email__c | 社員の別の特定手段。EmpId があれば補助 |
| teamspirit__StampType__c | 打刻種別。選択肢 1 / 2 / 21 / 22 / 31 / 32。**1=出社、2=退社 は仮定（要確認）** |
| teamspirit__StampedDate__c | 打刻日時（datetime、UTCで渡す） |
| teamspirit__Status__c | 未処理（既定）/ 処理済み / エラー / 削除 |
| teamspirit__Error__c / ErrorDetail__c | 処理エラーの内容 |
| teamspirit__InputDevice__c | 入力デバイス名（自由入力） |
| teamspirit__UniqueKey__c | 外部キー（自由入力） |
| teamspirit__AccessInfo__c | アクセス元情報（自由入力） |
| teamspirit__DeviceId__c / IDm__c / IpAddress__c / Location__c / WorkLocationCode__c | 打刻機向け項目。Claudeからは使わない |

処理は TeamSpirit の `ExternalAttendanceTriggerHandler`（管理パッケージ内、中身は見えない）が担当していると思われる。
勤怠共通設定 `teamspirit__UseConnectICAttendance__c`（IC勤怠連携機能を利用する）がオフだと処理されない模様。

## 勤怠共通設定 teamspirit__AtkCommon__c

| 項目 | 2026-09-10 時点の値 |
|---|---|
| teamspirit__UseConnectICAttendance__c | false |
| teamspirit__DisableChatterPushTime__c | true（Chatter投稿での打刻は無効） |
| teamspirit__LimitedPushTimeIPList__c | 空（IP制限なし） |

## SOQL の日付について

`TODAY` はログインユーザーのタイムゾーン（Asia/Tokyo）で評価される。
datetime 項目を `= TODAY` で比較すると、その日の 0:00〜24:00 JST に含まれるかで判定される。
