#!/usr/bin/env python3
"""Generate xk_lcd.plg from the files in src/.

Usage:  python3 build.py [--version YYYY.MM.DD] [--changes "text"]
        python3 build.py --check     # verify generated files match src/ (used by CI)
Edit the settings below once, then re-run after every change and commit
src/ plus the generated xk_lcd.plg, templates/xk_lcd.xml and ca_profile.xml.
"""
import argparse, base64, datetime, hashlib, pathlib, re, sys, xml.dom.minidom

HOST     = "github"          # "github" (needed for Community Apps) or "gitlab"
OWNER    = "JamesSeymour"      # <-- your GitHub/GitLab username
PROJECT  = "xikestor-lcd"
BRANCH   = "main"
SUPPORT  = ""                # Unraid forum support thread URL; blank = repo issues

if HOST == "github":
    PROJECT_URL = f"https://github.com/{OWNER}/{PROJECT}"
    RAW = f"https://raw.githubusercontent.com/{OWNER}/{PROJECT}/{BRANCH}"
else:
    PROJECT_URL = f"https://gitlab.com/{OWNER}/{PROJECT}"
    RAW = f"{PROJECT_URL}/-/raw/{BRANCH}"
SUPPORT_URL = SUPPORT or f"{PROJECT_URL}/issues"

ROOT = pathlib.Path(__file__).parent
SRC = ROOT / "src"

FILES = [  # (source file, install path relative to the plugin dir, mode)
    ("xk_lcd.php",       "scripts/xk_lcd.php", "0644"),
    ("rc.xk_lcd",        "scripts/rc.xk_lcd", "0755"),
    ("XikeStorLCD.page", "XikeStorLCD.page",  "0644"),
    ("default.cfg",      "default.cfg",       "0644"),
]

TEMPLATE = """<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "xk_lcd">
<!ENTITY author    "{author}">
<!ENTITY version   "{version}">
<!ENTITY project   "{project_url}">
<!ENTITY pluginURL "{raw}/xk_lcd.plg">
<!ENTITY emhttp    "/usr/local/emhttp/plugins/&name;">
<!ENTITY plugdir   "/boot/config/plugins/&name;">
]>

<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;"
        support="{support}" icon="desktop" min="6.12.0">

<CHANGES>
###&version;
{changes}
</CHANGES>

<!-- Stop any running instance before files are replaced -->
<FILE Run="/bin/bash">
<INLINE>
if [ -x &emhttp;/scripts/rc.xk_lcd ]; then &emhttp;/scripts/rc.xk_lcd stop; fi
</INLINE>
</FILE>

{files}
<!-- ioctl helper (built from src/xk_hidfeat.c by build_helper.sh), base64-encoded -->
<FILE Name="&emhttp;/scripts/xk_hidfeat.b64" Mode="0644">
<INLINE>
{helper_b64}
</INLINE>
</FILE>

<!-- Post-install: unpack + verify helper, seed config, check prerequisites, start -->
<FILE Run="/bin/bash">
<INLINE>
base64 -d &emhttp;/scripts/xk_hidfeat.b64 &gt; &emhttp;/scripts/xk_hidfeat
rm -f &emhttp;/scripts/xk_hidfeat.b64
if [ "$(sha256sum &emhttp;/scripts/xk_hidfeat | cut -d' ' -f1)" != "{helper_sha}" ]; then
  echo "ERROR: xk_hidfeat checksum mismatch - aborting"
  rm -f &emhttp;/scripts/xk_hidfeat
  exit 1
fi
chmod 0755 &emhttp;/scripts/xk_hidfeat
mkdir -p &plugdir;
if [ ! -f &plugdir;/&name;.cfg ]; then cp &emhttp;/default.cfg &plugdir;/&name;.cfg; fi
if ! grep -qs 374A:0000B101 /sys/class/hidraw/*/device/uevent; then
  echo "WARNING: XikeStor panel (USB 374a:b101) not detected on this system."
fi
if grep -qsE "xk_lcd\\.(py|php)" /boot/config/go; then
  echo "WARNING: /boot/config/go also starts xk_lcd - remove those lines to avoid two instances."
fi
&emhttp;/scripts/rc.xk_lcd boot
echo ""
echo "xk_lcd &version; installed. Settings: Settings > Utilities > XikeStor LCD"
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
&emhttp;/scripts/rc.xk_lcd stop
rm -rf &emhttp; &plugdir;
rm -f /var/log/xk_lcd.log
echo "xk_lcd removed"
</INLINE>
</FILE>

</PLUGIN>
"""

FILE_TMPL = """<FILE Name="&emhttp;/{dest}" Mode="{mode}">
<INLINE><![CDATA[{body}]]></INLINE>
</FILE>

"""

CA_TEMPLATE = """<?xml version="1.0"?>
<Plugin>
  <Name>XikeStor LCD</Name>
  <PluginURL>{raw}/xk_lcd.plg</PluginURL>
  <Project>{project_url}</Project>
  <Support>{support}</Support>
  <Icon>{raw}/icon.png</Icon>
  <Category>Tools:Utilities</Category>
  <MinVer>6.12.0</MinVer>
  <License>MIT</License>
  <ReadMe>{project_url}#readme</ReadMe>
  <Overview>Drives the front-panel LCD on XikeStor NAS units, which otherwise stays on
"STARTING SYSTEM" under Unraid. Shows CPU/system/disk temperatures, array capacity and
network details (never waking spun-down disks), and lets the panel menu cleanly shut down
or reboot the server. Configure under Settings - Utilities - XikeStor LCD.</Overview>
  <Requires>A XikeStor NAS with the front-panel LCD (USB device 374a:b101, "HOLTEK USB-HID AP"). No other dependencies.</Requires>
  <ExtraSearchTerms>xikestor lcd front panel display holtek nas screen</ExtraSearchTerms>
</Plugin>
"""

CA_PROFILE = """<?xml version="1.0"?>
<CommunityApplications>
  <Profile>Unraid support for XikeStor NAS hardware. Currently: the XikeStor LCD plugin, which
drives the front-panel display and its menu. Report problems or other XikeStor models via the
support link.</Profile>
  <Icon>{raw}/icon.png</Icon>
  <WebPage>{project_url}</WebPage>
  <Forum>{support}</Forum>
</CommunityApplications>
"""

def render(version, changes_text):
    """Return {relative path: file contents} for every generated file."""
    blocks = []
    for src, dest, mode in FILES:
        body = (SRC / src).read_text().replace("PROJECT_URL", PROJECT_URL)
        if "]]>" in body:
            raise SystemExit(f"{src} contains ']]>' which would break the CDATA block")
        blocks.append(FILE_TMPL.format(dest=dest, mode=mode, body=body))

    changes = "\n".join(l if l.startswith("-") else f"- {l}" for l in changes_text.splitlines())
    helper = ROOT / "bin" / "xk_hidfeat"
    if not helper.exists():
        raise SystemExit("bin/xk_hidfeat missing - run ./build_helper.sh first")
    raw_bin = helper.read_bytes()
    helper_sha = hashlib.sha256(raw_bin).hexdigest()
    b64 = base64.encodebytes(raw_bin).decode().strip()

    plg = TEMPLATE.format(helper_b64=b64, helper_sha=helper_sha, author=OWNER, project_url=PROJECT_URL, raw=RAW, support=SUPPORT_URL,
                          version=version, changes=changes, files="".join(blocks))
    outputs = {
        "xk_lcd.plg": plg,
        "templates/xk_lcd.xml": CA_TEMPLATE.format(raw=RAW, project_url=PROJECT_URL,
                                                   support=SUPPORT_URL),
        "ca_profile.xml": CA_PROFILE.format(raw=RAW, project_url=PROJECT_URL,
                                            support=SUPPORT_URL),
    }
    for text in outputs.values():
        xml.dom.minidom.parseString(text.encode())     # fail loudly on malformed XML
    return outputs, helper_sha

def current_release():
    """Version and changelog of the committed xk_lcd.plg, so --check can re-render it."""
    plg = (ROOT / "xk_lcd.plg").read_text()
    version = re.search(r'<!ENTITY version\s+"([^"]+)">', plg).group(1)
    changes = re.search(r"<CHANGES>\n###&version;\n(.*?)\n</CHANGES>", plg, re.S).group(1)
    return version, changes

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--version", default=datetime.date.today().strftime("%Y.%m.%d"))
    ap.add_argument("--changes", default="- See the project README and commit history")
    ap.add_argument("--check", action="store_true",
                    help="verify the committed generated files match src/; write nothing")
    a = ap.parse_args()

    if a.check:
        outputs, helper_sha = render(*current_release())
        stale = [n for n, t in outputs.items()
                 if not (ROOT / n).exists() or (ROOT / n).read_text() != t]
        if stale:
            print("Out of date (run build.py and commit): " + ", ".join(stale))
            sys.exit(1)
        print(f"generated files are up to date (helper sha256 {helper_sha})")
        return

    outputs, helper_sha = render(a.version, a.changes)
    for name, text in outputs.items():
        path = ROOT / name
        path.parent.mkdir(exist_ok=True)
        path.write_text(text)
        print(f"wrote {name}")
    print(f"helper sha256 {helper_sha}")
    if OWNER == "REPO_OWNER":
        print("WARNING: OWNER is still the placeholder - set it at the top of build.py")

if __name__ == "__main__":
    main()
