# Sourced by the command wrappers. Fills UNSET variables from the user's defaults file, one KEY=VALUE per
# line (default ${XDG_CONFIG_HOME:-~/.config}/mcxbmcview/defaults, or BMC_DEFAULTS_FILE). The environment
# always wins, only the keys below are read, and nothing in the file is executed. The file must belong to
# the user and not be writable by group or others, because it can hold the BMC password. lib/bmcEnv.php
# (BmcEnv::withDefaults) reads the same file the same way for PHP callers.
mcxDefaults="${BMC_DEFAULTS_FILE:-${XDG_CONFIG_HOME:-$HOME/.config}/mcxbmcview/defaults}"
if [ -f "$mcxDefaults" ] && [ -O "$mcxDefaults" ] && [ -z "$(find "$mcxDefaults" -perm /022)" ]; then
    while IFS='=' read -r mcxKey mcxValue || [ -n "$mcxKey" ]; do
        case "$mcxKey" in
            BMC_USER|BMC_PASS|BMC_USER_CANDIDATES|BMC_LOG_DIR|BMC_ADDRESS_PREFIX|BMC_HOST_PREFIX_MAP|NODE_PATH|IDRAC6_JAVA)
                if [ -z "${!mcxKey:-}" ]; then export "$mcxKey=${mcxValue%$'\r'}"; fi ;;
        esac
    done < "$mcxDefaults"
fi
unset mcxDefaults mcxKey mcxValue
