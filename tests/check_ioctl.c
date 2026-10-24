/* Verifies the hand-written ioctl numbers in src/xk_hidfeat.c match the kernel header. */
#include <stdio.h>
#include <sys/ioctl.h>
#include <linux/hidraw.h>
#define HID_IOC(nr, len) ((3UL << 30) | ((unsigned long)(len) << 16) | ('H' << 8) | (nr))
int main(void) {
    int ok = HIDIOCSFEATURE(9) == HID_IOC(0x06, 9) && HIDIOCGFEATURE(9) == HID_IOC(0x07, 9);
    puts(ok ? "ioctl numbers match <linux/hidraw.h>" : "ioctl numbers DIFFER from <linux/hidraw.h>");
    return !ok;
}
