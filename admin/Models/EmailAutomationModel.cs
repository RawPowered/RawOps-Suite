namespace Product.Admin.Models
{
    public class EmailAutomationModel
    {
        public string Id { get; set; }
        public string Slug { get; set; }
        public string Name { get; set; }
        public string Subject { get; set; }
        public string Scope { get; set; }
        public string HtmlBody { get; set; }
        public bool IsActive { get; set; }
    }
}
