/*
 * xk_hidfeat - minimal HID feature-report bridge for the XikeStor LCD plugin.
 *
 * PHP cannot call ioctl(), and the panel only speaks HID feature reports, so the
 * PHP daemon runs this as a co-process and talks to it over stdin/stdout:
 *
 *   usage: xk_hidfeat /dev/hidrawN
 *   stdin  "S <18 hex digits>"  -> HIDIOCSFEATURE with those 9 bytes -> "OK"
 *   stdin  "G"                  -> HIDIOCGFEATURE (9 bytes)          -> "R <18 hex digits>"
 *   on failure                                                       -> "ERR <errno> <message>"
 *   exits on EOF, or with status 2 if the device cannot be opened.
 *
 * Build: gcc -static -Os -s -o xk_hidfeat xk_hidfeat.c   (musl or glibc)
 * MIT licence - PROJECT_URL
 */
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <string.h>
#include <sys/ioctl.h>
#include <unistd.h>

#define LEN 9

/* _IOC(_IOC_READ|_IOC_WRITE, 'H', nr, len) - same values as <linux/hidraw.h> */
#define HID_IOC(nr, len) ((3UL << 30) | ((unsigned long)(len) << 16) | ('H' << 8) | (nr))
#define HIDIOCSFEATURE(len) HID_IOC(0x06, len)
#define HIDIOCGFEATURE(len) HID_IOC(0x07, len)

static int parse_hex(const char *s, unsigned char *out) {
    for (int i = 0; i < LEN; i++) {
        unsigned int v;
        if (sscanf(s + 2 * i, "%2x", &v) != 1) return -1;
        out[i] = (unsigned char)v;
    }
    return 0;
}

static void reply_err(void) {
    printf("ERR %d %s\n", errno, strerror(errno));
}

int main(int argc, char **argv) {
    if (argc != 2) {
        fprintf(stderr, "usage: %s /dev/hidrawN\n", argv[0]);
        return 1;
    }
    int fd = open(argv[1], O_RDWR);
    if (fd < 0) {
        reply_err();
        fflush(stdout);
        return 2;
    }
    setvbuf(stdout, NULL, _IOLBF, 0);

    char line[128];
    unsigned char buf[LEN];
    while (fgets(line, sizeof line, stdin)) {
        if (line[0] == 'S' && line[1] == ' ') {
            if (strlen(line) < 2 + 2 * LEN || parse_hex(line + 2, buf) < 0) {
                printf("ERR %d bad frame\n", EINVAL);
            } else if (ioctl(fd, HIDIOCSFEATURE(LEN), buf) < 0) {
                reply_err();
            } else {
                printf("OK\n");
            }
        } else if (line[0] == 'G') {
            memset(buf, 0, LEN);
            if (ioctl(fd, HIDIOCGFEATURE(LEN), buf) < 0) {
                reply_err();
            } else {
                printf("R ");
                for (int i = 0; i < LEN; i++) printf("%02x", buf[i]);
                printf("\n");
            }
        } else {
            printf("ERR %d unknown command\n", EINVAL);
        }
    }
    close(fd);
    return 0;
}
