# 发布指南

> 本文档面向**包维护者**，描述如何把 `kode/messaging` 的变更发布到 GitHub、Packagist、Docker Hub。
> 普通用户无需阅读本文件。

## 1. 仓库总览

`kode/messaging` 维护在以下 3 个仓库，**任何发布必须 3 处一致**：

| 仓库 | 用途 | 触发方式 |
|---|---|---|
| `github.com/kodephp/messaging` | 源码、Issues、PR、Release | `git push` |
| `packagist.org/packages/kode/messaging` | composer 索引 | GitHub webhook |
| Docker Hub `kode/messaging` | 容器镜像 | GitHub Action 监听 tag |

## 2. 版本号规范（SemVer）

```
MAJOR.MINOR.PATCH[-PRERELEASE]

例：
  2.1.0         稳定版
  2.1.0-rc.1    预发版
  2.1.0-alpha.1 内测
  2.1.0-beta.2  公测
```

| 段 | 何时递增 | 示例 |
|---|---|---|
| MAJOR | 破坏性 API 变更、PHP 最低版本提升 | 1.5.0 → 2.0.0 |
| MINOR | 新增功能、新协议、新事件 | 2.0.0 → 2.1.0 |
| PATCH | bug 修复、性能优化、文档 | 2.1.0 → 2.1.1 |
| PRERELEASE | RC / beta / alpha | 2.1.0 → 2.1.0-rc.1 |

**版本号三处一致**：

1. `src/Support/Version.php` 的 `MAJOR` / `MINOR` / `PATCH` 常量
2. `composer.json` 的 `version` 字段
3. `CHANGELOG.md` 顶部段落
4. git tag `vX.Y.Z`

## 3. 发布流程

### 3.1 前置准备

```bash
# 1. 拉取最新代码
git checkout main
git pull --rebase

# 2. 工作区干净
git status   # 应该显示 "nothing to commit, working tree clean"

# 3. 环境自检
php bin/messaging self-check

# 4. 测试通过
vendor/bin/phpunit

# 5. composer.json 合法
composer validate --strict
```

### 3.2 预演（dry-run）

**永远先 dry-run**：

```bash
php bin/messaging release --bump=minor --dry-run
```

输出示例：

```
当前版本: 2.0.0
新版本:   2.1.0
（dry-run，未实际修改）
✓ 发布完成 v2.1.0
```

预演不会修改任何文件、不会 git commit、不会 push。检查：
- 新版本号符合预期
- bump 类型正确（patch / minor / major / prerelease）

### 3.3 正式发布

```bash
php bin/messaging release --bump=minor --commit --tag --push
```

实际执行：

1. 读 `src/Support/Version.php` → 计算新版本号
2. 写 `src/Support/Version.php`（更新 MAJOR/MINOR/PATCH）
3. 写 `composer.json`（更新 `version` 字段）
4. 写 `CHANGELOG.md`（插入新版本段）
5. `git add -A`
6. `git commit -m "chore(release): bump to v2.1.0"`
7. `git tag -a v2.1.0 -m "Release v2.1.0"`
8. `git push origin main`
9. `git push origin v2.1.0`

### 3.4 自定义 commit / tag 信息

```bash
# 自定义 commit 信息
php bin/messaging release --bump=minor --message="feat: 11 protocols stable" \
    --commit --tag --push

# 自定义预发版后缀
php bin/messaging release --bump=prerelease --pre=rc.1 --commit --tag --push
# 2.1.0 → 2.1.0-rc.1
```

### 3.5 GitHub Release

`git push --tag` 不会自动创建 GitHub Release。维护者需：

1. 打开 https://github.com/kodephp/messaging/releases/new
2. 选择刚才推送的 `v2.1.0` tag
3. Release title：`v2.1.0` 或 `kode/messaging v2.1.0`
4. 描述从 `CHANGELOG.md` 复制对应段落
5. 点 "Publish release"

## 4. bump 类型详解

| `--bump` | 旧 → 新 | 适用 | 触发规则 |
|---|---|---|---|
| `patch` | `2.1.0` → `2.1.1` | bug 修复、性能 | `fix:` / `perf:` |
| `minor` | `2.1.0` → `2.2.0` | 新功能 | `feat:` |
| `major` | `2.1.0` → `3.0.0` | 破坏性变更 | `feat!:` / `BREAKING CHANGE` |
| `prerelease` | `2.1.0` → `2.1.0-rc.1` | 预发版 | RC / beta / alpha |

> 也可跳过自动判断，手动指定 `--bump=patch` 等。

## 5. 紧急热修（hotfix）

```bash
git checkout main
git pull --rebase

# 修代码（最小变更）
# 加测试
vendor/bin/phpunit

# commit
git add -A
git commit -m "fix(ws): critical frame parsing bug"

# 发布
php bin/messaging release --bump=patch --commit --tag --push

# 立即 GitHub Release + 通知用户
```

## 6. 预发版（RC）

```bash
php bin/messaging release --bump=prerelease --pre=rc.1 --commit --tag --push
```

tag 形如 `v2.1.0-rc.1`。Packagist 会自动识别为预发版，composer 需指定 `dev-main` 或 `^2.1@rc` 安装。

## 7. 撤销发布

> ⚠ **尽量避免**。如果必须：

```bash
# 删除本地 tag
git tag -d v2.1.0

# 删除远程 tag
git push origin :refs/tags/v2.1.0

# 回滚 commit（如果还没人引用）
git revert <commit-sha>
git push origin main
```

> Packagist 上的版本号一旦发布无法删除，只能 `unflag` 或 `# flag`（管理后台）。所以发布前**务必**确认。

## 8. 发布到 Packagist

`kode/messaging` 已经配置 GitHub webhook，每次 `git push` 时 Packagist 自动同步。

如果 webhook 失效，手动触发：

1. 打开 https://packagist.org/packages/kode/messaging
2. 点击 "Update package"
3. 等待 30 秒

## 9. 发布 Docker 镜像

`.github/workflows/docker.yml`（待补）监听 tag push：

```yaml
on:
  push:
    tags: ['v*']
jobs:
  docker:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: docker/build-push-action@v5
        with:
          push: true
          tags: |
            kode/messaging:${{ github.ref_name }}
            kode/messaging:latest
          file: docker/Dockerfile
```

手动触发：

```bash
docker build -t kode/messaging:v2.1.0 -f docker/Dockerfile .
docker push kode/messaging:v2.1.0
docker push kode/messaging:latest
```

## 10. 故障排查

### 10.1 Packagist 没更新

1. 检查 GitHub webhook：https://github.com/kodephp/messaging/settings/hooks
2. Packagist 错误日志：https://packagist.org/api/github
3. 手动触发更新

### 10.2 `release` 命令报 "缺少文件"

确认当前目录是包根目录：

```bash
ls src/Support/Version.php composer.json CHANGELOG.md
```

### 10.3 `git push` 失败

```bash
# 先 fetch + rebase
git fetch origin
git rebase origin/main
php bin/messaging release --bump=patch --commit --tag --push
```

### 10.4 composer.json 改坏了

```bash
# 回退 release 改动
git reset --hard HEAD~1
git tag -d v2.1.0
git push origin :refs/tags/v2.1.0
```

## 11. 发布 checklist

发布前 **逐项勾选**：

- [ ] 工作区干净（`git status`）
- [ ] 当前在 `main` 分支
- [ ] `composer validate --strict` 通过
- [ ] `vendor/bin/phpunit` 110/110 通过
- [ ] `php bin/messaging self-check` 通过
- [ ] `php-cs-fixer fix --dry-run` 通过
- [ ] `phpstan analyse` 通过
- [ ] `CHANGELOG.md` 已记录本次变更
- [ ] 文档同步更新（`docs/`、`README.md`）
- [ ] `--dry-run` 预演效果正确
- [ ] 选好 `--bump` 类型
- [ ] 已通知协作者（避免并行 push）

## 12. 下一步

- 项目规则：[.trae/rules/project_rules.md §11](../.trae/rules/project_rules.md)
- 架构：[docs/architecture.md](./architecture.md)
- 部署：[docs/deployment.md](./deployment.md)
- 迁移：[docs/migration.md](./migration.md)
