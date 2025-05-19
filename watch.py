import tkinter as tk
import time


def update_time(label):
    current_time = time.strftime('%H:%M:%S')
    label.config(text=current_time)
    label.after(1000, update_time, label)


def main():
    root = tk.Tk()
    root.title('Watch')
    label = tk.Label(root, font=('Helvetica', 48), fg='black')
    label.pack(padx=20, pady=20)
    update_time(label)
    root.mainloop()


if __name__ == '__main__':
    main()
