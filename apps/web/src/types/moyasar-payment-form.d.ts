// moyasar-payment-form 2.3.0 ships typings its package.json "exports" hide,
// and they leave the options untyped. These are the options this site uses.
declare module "moyasar-payment-form" {
  const Moyasar: {
    init(options: import("@/lib/payments").MoyasarFormOptions): void;
    setAmount(amount: number): void;
  };
  export default Moyasar;
}
