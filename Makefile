app_name=files_snapshots
project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build/artifacts
sign_dir=$(build_dir)/sign
cert_dir=$(HOME)/.nextcloud/certificates

sources=$(wildcard src/*.js) $(wildcard src/*/*.js) webpack.config.js

all: build/files_versions.js

build/files_versions.js: $(sources)
	node_modules/.bin/webpack --mode production --progress --hide-modules --config webpack.config.js

clean:
	rm -rf $(build_dir)

appstore: clean build/files_versions.js
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=.git \
	--exclude=build/artifacts \
	--exclude=.gitignore \
	--exclude=Makefile \
	--exclude=node_modules \
	--exclude=screenshots \
	--exclude=phpunit*xml \
	$(project_dir) $(sign_dir)
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)
	openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(build_dir)/$(app_name).tar.gz | openssl base64
